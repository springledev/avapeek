# Garage's official image is scratch-based (no shell), so the one-shot
# provisioning container copies the binary into a minimal Alpine image.
FROM dxflrs/garage:v2.5.0 AS garage

FROM alpine:3.24
COPY --from=garage /garage /usr/local/bin/garage
COPY docker/garage/init.sh /init.sh
RUN chmod +x /init.sh
ENTRYPOINT ["/init.sh"]
