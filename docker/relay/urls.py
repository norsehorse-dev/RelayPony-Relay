from django.urls import re_path

from signaling.views import signal

urlpatterns = [
    re_path(r'^(?:api/|v1/)?signal/?$', signal, name='signal'),
]

