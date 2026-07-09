# Nimbus Cloud Transfer

Upload large files from the Internet directly to your Personal Cloud storage in minutes—without consuming your local device's bandwidth or disk space. 

Nimbus Cloud Transfer uses an elegant streaming architecture. It acts as a middleman process (running on your server) that pipes HTTP/HTTPS download streams directly into cloud storage providers (Google Drive, WebDAV) on the fly. 

## Features
- **Zero Local Footprint**: Streams are passed entirely in memory. It can transfer a 100GB file on a server with only 500MB of free disk space.
- **Robust Dashboard**: Real-time analytics, dynamic progress bars, and easy queue management using Bootstrap 5 and AJAX.
- **Concurrency**: Spin up independent background workers for parallel processing without blocking the UI.
- **Universal Deployment**: Runs natively on basic PHP 8+ and SQLite. No heavy databases required.

## Getting Started

### 1. Requirements
- PHP 8.0 or newer (with `curl` and `sqlite3` extensions enabled)
- A Google Cloud Platform (GCP) project for OAuth 2.0 credentials

### 2. Configuration
1. Rename `.env.example` to `.env` (or create one).
2. Add your Google OAuth credentials:
```env
GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-client-secret
```

### 3. Local Development
If you are running Nimbus locally on Windows using the built-in PHP development server, use the following command:
```bash
./php/php.exe -S localhost:8000
```
Then navigate to `http://localhost:8000`.

> **Note**: The built-in PHP server is strictly single-threaded. During development, Nimbus uses standard AJAX short-polling for UI updates to ensure the server remains unblocked and can handle API actions concurrently.

### 4. Production Deployment
For production, a proper web server (Apache/Nginx with PHP-FPM) is highly recommended. Because Nimbus utilizes `exec()` and `popen()` to spawn background stream workers, it requires a host without stringent process execution restrictions.

#### Docker & Kubernetes
Kubernetes deployment manifests are included in the `/docker/k8s/` directory. For a scalable architecture, you can containerize the worker nodes and run the frontend behind a load balancer.

## Architecture

- **`index.html` & `js/main.js`**: The responsive Bootstrap frontend that orchestrates the UI, handles local validations, and polls for updates.
- **`upload.php`**: The entry point that accepts an array of URLs and queues them into the `jobs` table in the SQLite database.
- **`worker.php`**: A headless, standalone PHP script that is spawned entirely in the background. It negotiates the download stream (`CURLOPT_READFUNCTION`) and instantly relays packets to the configured cloud provider using their respective API.
- **`poll.php` / `dashboard_stats.php`**: Lightweight endpoints that aggregate current job progress and file transfer metrics for the dashboard UI.

## Security
Nimbus has been updated with modern security parameters. Review the `SECURITY.md` file for details on vulnerability handling, patch disclosures, and input sanitization practices (e.g. defense against SSRF and Path Traversal).
