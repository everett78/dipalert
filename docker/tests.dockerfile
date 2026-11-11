ARG PHP_VERSION=8.4
ARG NODE_VERSION=20

FROM node:${NODE_VERSION}-bookworm-slim AS node

FROM jez500/pricebuddy-base-${PHP_VERSION}:latest

# Install node
COPY --from=node /usr/lib /usr/lib
COPY --from=node /usr/local/lib /usr/local/lib
COPY --from=node /usr/local/include /usr/local/include
COPY --from=node /usr/local/bin /usr/local/bin
RUN node -v && npm -v

