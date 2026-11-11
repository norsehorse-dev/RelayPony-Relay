import contextlib
import json
import logging
import re

from datetime import datetime, timedelta
from typing import TypeAlias

from django.core.exceptions import RequestDataTooBig
from django.db import DatabaseError, transaction
from django.http import JsonResponse
from django.utils import timezone
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_POST

from ._settings import MAX_TAKE_CEILING, settings, validated_max_batch
from .models import RelayDatum, SignalBlob


Data: TypeAlias = dict[str, str | list[str]]

B64_RE = re.compile(r'^[A-Za-z0-9+/]+[=]*$')
HANDLE_RE = re.compile(r'age1[0-9a-z]{20,120}')
SESSION_RE = re.compile(r'[0-9a-f]{8,64}')

log = logging.getLogger(__name__)


def _max_body_bytes() -> int:
    max_batch = validated_max_batch()
    max_send = settings.SIGNAL_MAX_PAYLOAD
    max_data = (4 + settings.RELAY_MAX_CHUNK) * max_batch
    overhead = 512
    return overhead + max(max_send, max_data)


def _read_json_body(request) -> dict | JsonResponse:
    max_bytes = _max_body_bytes()

    content_length = request.META.get('CONTENT_LENGTH')
    if content_length:
        try:
            if int(content_length) > max_bytes:
                return fail(413, 'payload_too_large')
        except ValueError:
            return fail(400, 'bad_json')

    try:
        raw = request.read(max_bytes + 1)
    except RequestDataTooBig:
        return fail(413, 'payload_too_large')

    if max_bytes < len(raw):
        return fail(413, 'payload_too_large')

    try:
        data = json.loads(raw or b'{}')
    except (ValueError, UnicodeDecodeError):
        return fail(400, 'bad_json')
    else:
        if not isinstance(data, dict):
            return fail(400, 'bad_json')
        return data


def _expired(qs, now: datetime):
    return qs.filter(expires_at__lt=now)


def _unexpired(qs, now: datetime):
    return qs.filter(expires_at__gte=now)


def _queue_full(qs, /, maximum: int, incoming: int = 1) -> bool:
    return maximum < (incoming + qs.count())


def ok(**extra) -> JsonResponse:
    return JsonResponse({'ok': True, **extra})


def fail(status: int, msg: str) -> JsonResponse:
    return JsonResponse({'ok': False, 'error': msg}, status=status)


def _text(data: Data, key: str, strip: bool = True) -> str:
    if not isinstance(data, dict):
        return ''
    value = data.get(key)
    if not isinstance(value, str):
        return ''
    return value.strip() if strip else value


def _reap(now: datetime) -> None:
    """Drop expired rows. Non-fatal, matching the PHP version."""
    for model in (SignalBlob, RelayDatum):
        try:
            _expired(model.objects.all(), now).delete()
        except DatabaseError:
            log.exception('reap failed for %s', model.__name__)


def _take(queryset, limit: int) -> list:
    """Read up to `limit` rows oldest-first and delete them in one transaction."""
    if not (0 < limit <= MAX_TAKE_CEILING):
        raise ValueError('"limit" was not above zero or was over nine hundred')
    with transaction.atomic(durable=True):
        rows = list(queryset.select_for_update().order_by('id')[:limit])
        if rows:
            queryset.model.objects.filter(pk__in={r.pk for r in rows}).delete()
    return rows


def _send(data: Data, now: datetime) -> JsonResponse:
    to = _text(data, 'to')
    if not HANDLE_RE.fullmatch(to):
        return fail(422, 'bad_recipient')

    payload = _text(data, 'payload', strip=False)
    # validate payload by size and base64 alphabet
    if not ((0 < len(payload) <= settings.SIGNAL_MAX_PAYLOAD) and B64_RE.fullmatch(payload)):
        return fail(422, 'bad_payload')

    signal_expires = now + timedelta(seconds=settings.SIGNAL_TTL_SECONDS)
    with transaction.atomic(durable=True):
        qs = _unexpired(SignalBlob.objects.filter(recipient=to), now)
        if _queue_full(qs, maximum=settings.SIGNAL_MAX_QUEUE):
            return fail(429, 'queue_full')
        SignalBlob.objects.create(recipient=to, payload=payload, expires_at=signal_expires)
    return ok()


def _poll(data: Data, now: datetime) -> JsonResponse:
    handle = _text(data, 'for')
    if not HANDLE_RE.fullmatch(handle):
        return fail(422, 'bad_recipient')

    qs = _unexpired(SignalBlob.objects.filter(recipient=handle), now)
    rows = _take(qs, settings.SIGNAL_POLL_LIMIT)
    return ok(blobs=[r.payload for r in rows])


def _data(data: Data, now: datetime) -> JsonResponse:
    to = _text(data, 'to')
    if not HANDLE_RE.fullmatch(to):
        return fail(422, 'bad_recipient')

    session = _text(data, 'session').lower()
    if not SESSION_RE.fullmatch(session):
        return fail(422, 'bad_session')

    chunks = data.get('chunks')
    if not (isinstance(chunks, list) and (0 < (chunks_length := len(chunks)) <= validated_max_batch())):
        return fail(422, 'bad_batch')
    for chunk in chunks:
        accept_chunk = (
            isinstance(chunk, str)
            and 0 < len(chunk) <= settings.RELAY_MAX_CHUNK
            and B64_RE.fullmatch(chunk)
        )
        if not accept_chunk:
            return fail(422, 'bad_chunk')

    relay_expires = now + timedelta(seconds=settings.RELAY_TTL_SECONDS)
    with transaction.atomic(durable=True):
        qs = _unexpired(RelayDatum.objects.filter(recipient=to, session=session), now)
        if _queue_full(qs, incoming=chunks_length, maximum=settings.RELAY_MAX_QUEUE):
            return fail(429, 'queue_full')
        RelayDatum.objects.bulk_create(
            RelayDatum(recipient=to, session=session, payload=chunk, expires_at=relay_expires)
            for chunk in chunks
        )
    return ok(stored=chunks_length)


def _fetch(data: Data, now: datetime) -> JsonResponse:
    handle = _text(data, 'for')
    if not HANDLE_RE.fullmatch(handle):
        return fail(422, 'bad_recipient')

    session = _text(data, 'session').lower()
    if not SESSION_RE.fullmatch(session):
        return fail(422, 'bad_session')

    qs = _unexpired(RelayDatum.objects.filter(recipient=handle, session=session), now)
    rows = _take(qs, settings.RELAY_FETCH_LIMIT)
    return ok(chunks=[r.payload for r in rows])


HANDLERS = dict(send=_send, poll=_poll, data=_data, fetch=_fetch)


@csrf_exempt
@require_POST
def signal(request):
    parsed = _read_json_body(request)
    if isinstance(parsed, JsonResponse):
        return parsed
    data = parsed

    now = timezone.now()
    try:
        op = _text(data, 'op').lower()

        handler = HANDLERS.get(op, None)
        if handler is None:
            return fail(400, 'bad_op')

        try:
            return handler(data, now)
        except DatabaseError:
            log.exception('signal op %r failed', op)
            return fail(500, 'db_error')
    finally:
        with contextlib.suppress(Exception):
            _reap(now)

