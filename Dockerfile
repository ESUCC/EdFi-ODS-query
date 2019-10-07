FROM php:7.3-cli
RUN apt-get update && \
  apt-get install -y libpq-dev && \
  rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo_pgsql pgsql
COPY . /usr/src/adviser-publish
WORKDIR /usr/src/adviser-publish
CMD [ "php", "./publish-1920.php" ]
