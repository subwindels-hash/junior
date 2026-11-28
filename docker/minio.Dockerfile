# MinIO's public binary registries are no longer reliably available. Build the
# official AGPL source at an immutable release commit instead of relying on a
# removed image tag. This service is for local development only.
FROM golang:1.24.8-alpine AS build
RUN apk add --no-cache ca-certificates git
WORKDIR /src
ADD https://github.com/minio/minio/archive/9e49d5e7a648f00e26f2246f4dc28e6b07f8c84a.tar.gz /tmp/minio.tar.gz
RUN tar -xzf /tmp/minio.tar.gz --strip-components=1 -C /src \
    && CGO_ENABLED=0 go build -trimpath -o /minio .

FROM alpine:3.22
RUN apk add --no-cache ca-certificates curl
COPY --from=build /minio /usr/local/bin/minio
COPY --from=build /src/LICENSE /licenses/minio-LICENSE
EXPOSE 9000 9001
ENTRYPOINT ["minio"]
