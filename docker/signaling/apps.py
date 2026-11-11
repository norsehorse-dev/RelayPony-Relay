from django.apps import AppConfig


class SignalingConfig(AppConfig):
    default_auto_field = 'django.db.models.BigAutoField'
    name = 'signaling'

    def ready(self):
        from . import checks  # noqa: F401

