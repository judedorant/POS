# syntax=docker/dockerfile:1
FROM postgres:17-alpine
RUN --mount=type=secret,id=proxy_ca,target=/run/secrets/proxy_ca \
    cp /etc/ssl/cert.pem /tmp/original-ca.pem \
    && if [ -f /run/secrets/proxy_ca ]; then cat /run/secrets/proxy_ca >> /etc/ssl/cert.pem; fi \
    && apk add --no-cache age \
    && cat /tmp/original-ca.pem > /etc/ssl/cert.pem && rm /tmp/original-ca.pem
COPY --chmod=755 deploy/backup.sh /usr/local/bin/pos-backup
COPY --chmod=644 deploy/verify-restore.sql /usr/local/share/verify-restore.sql
USER 10001:10001
ENTRYPOINT ["/usr/local/bin/pos-backup"]
