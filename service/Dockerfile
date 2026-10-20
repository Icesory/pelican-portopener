FROM python:3.12-slim
WORKDIR /app
COPY port_opener.py /app/port_opener.py
COPY outbound.py /app/outbound.py
COPY ipv6.py /app/ipv6.py
COPY diagnostics.py diagnostics-schema.json /app/
RUN useradd --uid 10001 --create-home service && mkdir /state && chown service:service /state
USER service
CMD ["python", "/app/port_opener.py", "--config", "/config/service.json"]
