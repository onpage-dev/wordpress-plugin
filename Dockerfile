FROM wordpress:latest

RUN apt-get update \
    && apt-get install -y git \
    && rm -rf /var/lib/apt/lists/*

COPY docker/wordpress-dev-entrypoint.sh /usr/local/bin/wordpress-dev-entrypoint.sh

RUN chmod +x /usr/local/bin/wordpress-dev-entrypoint.sh

ENTRYPOINT ["wordpress-dev-entrypoint.sh"]
CMD ["apache2-foreground"]
