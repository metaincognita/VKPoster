FROM python:3.13-slim@sha256:3dd7cc108ec1493442514f5c2a871af6af0ec31d768ff6e378a93340c3b3db5f
ENV PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1
WORKDIR /spike
COPY requirements.txt .
RUN pip install --no-cache-dir --require-hashes -r requirements.txt && useradd --uid 1000 --create-home reader && mkdir /state && chown reader:reader /state
COPY reader reader
COPY tests tests
USER reader
ENTRYPOINT ["python", "-m", "reader.cli"]
CMD ["--help"]
