#!/bin/bash

# Nimbus Cloud Transfer - Database Backup Script
# Requires: sqlite3, openssl

DB_FILE="/app/nimbus.db"
BACKUP_DIR="/app/backups"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/nimbus_backup_${TIMESTAMP}.sqlite"
ENCRYPTED_FILE="${BACKUP_FILE}.enc"

# Ensure backup directory exists
mkdir -p "$BACKUP_DIR"

if [ -z "$BACKUP_PASSPHRASE" ]; then
    echo "ERROR: BACKUP_PASSPHRASE environment variable must be set."
    exit 1
fi

echo "Creating backup of $DB_FILE to $BACKUP_FILE..."
sqlite3 "$DB_FILE" ".backup '$BACKUP_FILE'"

if [ $? -eq 0 ]; then
    echo "Backup successful. Encrypting with OpenSSL..."
    # Encrypt the backup
    openssl enc -aes-256-cbc -salt -in "$BACKUP_FILE" -out "$ENCRYPTED_FILE" -k "$BACKUP_PASSPHRASE" -pbkdf2
    
    if [ $? -eq 0 ]; then
        echo "Encryption successful: $ENCRYPTED_FILE"
        # Remove unencrypted backup securely if possible, or just rm
        rm "$BACKUP_FILE"
    else
        echo "ERROR: Encryption failed."
        exit 1
    fi
else
    echo "ERROR: Backup failed."
    exit 1
fi
