FROM php:8.2-cli

# Install dependencies and extensions
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    git \
    unzip \
    && docker-php-ext-install curl pdo_sqlite pdo

# Set working directory
WORKDIR /app

# Copy application files
COPY . /app

# The database should be mounted as a volume to persist data
# Create a start script to handle whether we run as web server or worker
RUN echo '#!/bin/bash\n\
if [ "$ROLE" = "worker" ]; then\n\
    echo "Starting worker..."\n\
    php process_queue.php\n\
else\n\
    echo "Starting web server..."\n\
    php -S 0.0.0.0:8000\n\
fi' > /start.sh && chmod +x /start.sh

EXPOSE 8000

CMD ["/start.sh"]
