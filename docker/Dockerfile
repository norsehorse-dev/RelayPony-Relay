# syntax=docker/dockerfile:1
# check=error=true

FROM ghcr.io/astral-sh/uv:latest AS uv-binaries
FROM python:3-slim AS final

ENV DEBIAN_FRONTEND='teletype' \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_NO_COMPILE=1 \
    PIP_ROOT_USER_ACTION=ignore \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    UV_NO_MANAGED_PYTHON=1

RUN useradd --system --uid 901 --no-create-home -d /app/data/home app

RUN pip install --upgrade pip

USER app
WORKDIR /app
COPY --chown=app:app . .

RUN --mount=type=tmpfs,target=/home/app \
    --mount=type=bind,source=/uv,target=/usr/local/bin/uv,from=uv-binaries \
    --mount=type=bind,source=/uvx,target=/usr/local/bin/uvx,from=uv-binaries \
    mkdir data && mkdir data/home && \
    HOME=/home/app \
    uv --no-config \
    pip compile -o requirements.txt --generate-hashes requirements.in && \
    HOME=/home/app \
    uv venv .venv

USER root
RUN --mount=type=tmpfs,target=/home/root \
    --mount=type=bind,source=/uv,target=/usr/local/bin/uv,from=uv-binaries \
    --mount=type=bind,source=/uvx,target=/usr/local/bin/uvx,from=uv-binaries \
    chown root:root /app && \
    HOME=/home/root UV_SYSTEM_PYTHON=1 UV_BREAK_SYSTEM_PACKAGES=1 \
    uv --no-config \
    pip install --strict --require-hashes --requirements requirements.txt

USER app
EXPOSE 8000
VOLUME ["/app/data"]

ENTRYPOINT ["/app/entrypoint.sh", "gunicorn", "relay.wsgi:application", "--access-logfile", "-"]
CMD ["--workers", "4", "--bind", "0.0.0.0:8000"]

