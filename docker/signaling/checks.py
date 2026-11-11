from django.core.checks import Error, register

from ._settings import MAX_BATCH_CEILING, MAX_TAKE_CEILING, settings, validated_max_batch

REQUIRED_INT_SETTINGS = (
    'SIGNAL_MAX_PAYLOAD',
    'SIGNAL_TTL_SECONDS',
    'SIGNAL_MAX_QUEUE',
    'SIGNAL_POLL_LIMIT',
    'RELAY_MAX_BATCH',
    'RELAY_MAX_CHUNK',
    'RELAY_TTL_SECONDS',
    'RELAY_MAX_QUEUE',
    'RELAY_FETCH_LIMIT',
)


@register()
def check_signaling_limits(app_configs, **kwargs):
    errors = []
    valid_ints = set()

    for name in REQUIRED_INT_SETTINGS:
        try:
            val = getattr(settings, name)
        except AttributeError:
            errors.append(
                Error(
                    f'Missing required signaling setting: {name}.',
                    hint=f'Define {name} in SIGNALING or at the top level of your Django settings.',
                    id='signaling.E001',
                )
            )
            continue

        if isinstance(val, bool) or not isinstance(val, int) or val <= 0:
            errors.append(
                Error(
                    f'{name} must be a positive integer, got {val!r}.',
                    id='signaling.E002',
                )
            )
            continue

        valid_ints.add(name)

    if 'RELAY_MAX_BATCH' in valid_ints:
        try:
            validated_max_batch()
        except ValueError:
            errors.append(
                Error(
                    f'RELAY_MAX_BATCH must be between 1 and {MAX_BATCH_CEILING}.',
                    id='signaling.E003',
                )
            )

    for name in ('SIGNAL_POLL_LIMIT', 'RELAY_FETCH_LIMIT'):
        if name in valid_ints and getattr(settings, name) > MAX_TAKE_CEILING:
            errors.append(
                Error(
                    f'{name} must be between 1 and {MAX_TAKE_CEILING}.',
                    id='signaling.E004',
                )
            )

    return errors

