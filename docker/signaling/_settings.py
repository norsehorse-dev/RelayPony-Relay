from django.conf import settings as _django_conf_settings


MAX_BATCH_CEILING = 512
MAX_TAKE_CEILING = 900


class _SettingsProxy:
    _settings = _django_conf_settings

    def __getattr__(self, name: str):
        ns = getattr(self._settings, 'SIGNALING', None)
        if isinstance(ns, dict) and name in ns:
            return ns[name]
        if ns is not None and hasattr(ns, name):
            return getattr(ns, name)
        return getattr(self._settings, name)


settings = _SettingsProxy()
del _django_conf_settings


def validated_max_batch() -> int:
    limit = settings.RELAY_MAX_BATCH
    if not (0 < limit <= MAX_BATCH_CEILING):
        raise ValueError(f'RELAY_MAX_BATCH must be between 1 and {MAX_BATCH_CEILING}, got {limit!r}')
    return limit

