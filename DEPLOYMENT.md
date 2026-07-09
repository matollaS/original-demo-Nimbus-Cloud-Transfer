# Nimbus Cloud Transfer — Deployment Guide

Choose your deployment path based on scale, budget, and operational overhead.

---

## Quick Start (Local Development)

**Time:** 10 minutes | **Cost:** Free | **Scale:** 1 user

```bash
# 1. Clone repo
git clone https://github.com/matollaS/original-demo-Nimbus-Cloud-Transfer.git
cd original-demo-Nimbus-Cloud-Transfer

# 2. Configure
cp .env.example .env
# Edit .env with your Google OAuth credentials

# 3. Run (built-in PHP server)
php -S localhost:8000

# 4. Open browser
open http://localhost:8000
```

**Gotchas:**
- Single-threaded; uploads block each other
- No background workers (use for testing only)
- SQLite database only

---

## Option 1: Docker Compose (Recommended for First Deploy)

**Time:** 20 minutes | **Cost:** Free | **Scale:** 10–50 concurrent uploads

### Setup

```bash
# 1. Clone & configure
git clone https://github.com/matollaS/original-demo-Nimbus-Cloud-Transfer.git
cd original-demo-Nimbus-Cloud-Transfer
cp .env.example .env

# 2. Get Google OAuth credentials
# Go to: https://console.cloud.google.com/apis/credentials
# Create OAuth 2.0 Client ID (Web application)
# Authorized JavaScript origins: http://localhost:8000
# Authorized redirect URIs: http://localhost:8000/auth.php?action=callback
# Copy Client ID and Secret into .env

# 3. Build & run
docker-compose up --build

# 4. Access
open http://localhost:8000
```

### Production with Docker Compose

```bash
# 1. Use production image (Alpine base)
docker build -t nimbus-cloud:latest .

# 2. Production .env
cat > .env << EOF
APP_ENV=production
SESSION_SECRET=$(openssl rand -base64 32)
GOOGLE_CLIENT_ID=your-prod-client-id
GOOGLE_CLIENT_SECRET=your-prod-secret
GOOGLE_REDIRECT_URI=https://yourdomain.com/auth.php?action=callback
DATABASE_PATH=/data/nimbus.db
EOF

# 3. Docker Compose with volumes
cat > docker-compose.prod.yml << 'EOF'
version: '3.8'
services:
  nimbus-web:
    image: nimbus-cloud:latest
    container_name: nimbus-web
    environment:
      ROLE: web
      APP_ENV: production
    env_file: .env
    ports:
      - "127.0.0.1:8000:8000"
    volumes:
      - ./data:/data
      - ./logs:/var/log
    restart: always
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8000/"]
      interval: 30s
      timeout: 10s
      retries: 3
  
  nimbus-worker:
    image: nimbus-cloud:latest
    container_name: nimbus-worker
    environment:
      ROLE: worker
      APP_ENV: production
    env_file: .env
    volumes:
      - ./data:/data
      - ./logs:/var/log
    restart: always
    depends_on:
      - nimbus-web
EOF

# 4. Run
docker-compose -f docker-compose.prod.yml up -d

# 5. Verify
docker-compose logs -f nimbus-web
docker-compose logs -f nimbus-worker
```

**Pros:**
- Works on any machine (Linux, Mac, Windows)
- Easy to scale (spin up multiple workers)
- Persistent data via volumes

**Cons:**
- No public IP (need reverse proxy for HTTPS)
- Local storage only (no cloud-native features)

---

## Option 2: VPS Deployment (DigitalOcean, Linode, Vultr)

**Time:** 45 minutes | **Cost:** $5–15/month | **Scale:** 100–500 concurrent uploads

### Step 1: Create VPS

**DigitalOcean Droplet:**
```bash
# OS: Ubuntu 22.04 LTS
# Size: $6/month (1GB RAM, 25GB SSD) minimum
# Region: Pick closest to users
```

### Step 2: SSH & Install

```bash
ssh root@your-vps-ip

# Update system
apt update && apt upgrade -y

# Install Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh

# Install Docker Compose
curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
chmod +x /usr/local/bin/docker-compose

# Clone repo
cd /opt
git clone https://github.com/matollaS/original-demo-Nimbus-Cloud-Transfer.git nimbus-cloud
cd nimbus-cloud
```

### Step 3: Configure & Run

```bash
# Create .env
cp .env.example .env
nano .env
# Paste Google OAuth credentials

# Create data directory
mkdir -p data logs

# Run
docker-compose -f docker-compose.prod.yml up -d

# Verify
docker ps
curl http://localhost:8000
```

### Step 4: Reverse Proxy (HTTPS)

```bash
# Install Nginx
apt install nginx certbot python3-certbot-nginx -y

# Create Nginx config
cat > /etc/nginx/sites-available/nimbus-cloud << 'EOF'
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com www.yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;
    }
}
EOF

# Enable site
ln -s /etc/nginx/sites-available/nimbus-cloud /etc/nginx/sites-enabled/

# Get SSL cert (free)
certbot certonly --nginx -d yourdomain.com -d www.yourdomain.com

# Reload Nginx
systemctl reload nginx

# Auto-renew certs
systemctl enable certbot.timer
```

### Step 5: Monitoring

```bash
# View logs
docker-compose logs -f

# Backup database daily
cat > /usr/local/bin/backup-nimbus.sh << 'EOF'
#!/bin/bash
BACKUP_DIR="/opt/nimbus-cloud/backups"
mkdir -p $BACKUP_DIR
cp /opt/nimbus-cloud/data/nimbus.db $BACKUP_DIR/nimbus-$(date +%Y%m%d-%H%M%S).db
# Keep only last 30 days
find $BACKUP_DIR -mtime +30 -delete
EOF

chmod +x /usr/local/bin/backup-nimbus.sh

# Add to crontab
echo "0 2 * * * /usr/local/bin/backup-nimbus.sh" | crontab -
```

**Pros:**
- Full control
- HTTPS / custom domain
- Cheap & simple
- Can add 2–4 worker containers

**Cons:**
- Manual DevOps work
- Single point of failure
- Limited auto-scaling

---

## Option 3: Kubernetes (AWS EKS, DigitalOcean App Platform, GKE)

**Time:** 2 hours | **Cost:** $20–50/month | **Scale:** 1000+ concurrent uploads

### Using DigitalOcean App Platform (Easiest K8s)

```bash
# 1. Install doctl CLI
brew install doctl  # macOS
# Or: https://github.com/digitalocean/doctl

# 2. Authenticate
doctl auth init

# 3. Create app from spec
cat > app.yaml << 'EOF'
name: nimbus-cloud
services:
- name: nimbus-web
  image:
    registry_type: DOCKER_HUB
    repository: yourusername/nimbus-cloud:latest
  http_port: 8000
  envs:
  - key: ROLE
    value: web
  - key: APP_ENV
    value: production
  - key: GOOGLE_CLIENT_ID
    value: ${GOOGLE_CLIENT_ID}
  - key: GOOGLE_CLIENT_SECRET
    value: ${GOOGLE_CLIENT_SECRET}
  source_dir: /
  instance_count: 2
  instance_size_slug: basic-s

- name: nimbus-worker
  image:
    registry_type: DOCKER_HUB
    repository: yourusername/nimbus-cloud:latest
  envs:
  - key: ROLE
    value: worker
  - key: APP_ENV
    value: production
  - key: GOOGLE_CLIENT_ID
    value: ${GOOGLE_CLIENT_ID}
  - key: GOOGLE_CLIENT_SECRET
    value: ${GOOGLE_CLIENT_SECRET}
  source_dir: /
  instance_count: 3
  instance_size_slug: basic-s

- name: nimbus-db
  source_type: VOLUME
  volume_size_gb: 10
  filesystem_label: nimbus-data
EOF

# 4. Deploy
doctl apps create --spec app.yaml

# 5. Get URL
doctl apps list
```

### Using AWS EKS (Advanced)

```bash
# 1. Create EKS cluster
eksctl create cluster --name nimbus-cloud --region us-east-1 --nodes 2

# 2. Build & push Docker image
docker build -t nimbus-cloud:latest .
docker tag nimbus-cloud:latest YOUR_AWS_ACCOUNT.dkr.ecr.us-east-1.amazonaws.com/nimbus-cloud:latest
aws ecr get-login-password --region us-east-1 | docker login --username AWS --password-stdin YOUR_AWS_ACCOUNT.dkr.ecr.us-east-1.amazonaws.com
docker push YOUR_AWS_ACCOUNT.dkr.ecr.us-east-1.amazonaws.com/nimbus-cloud:latest

# 3. Deploy Kubernetes manifests
kubectl apply -f k8s/namespace.yaml
kubectl apply -f k8s/configmap.yaml
kubectl apply -f k8s/deployment-web.yaml
kubectl apply -f k8s/deployment-worker.yaml
kubectl apply -f k8s/service.yaml

# 4. Expose via load balancer
kubectl port-forward -n nimbus svc/nimbus-web 8000:80

# 5. Add ingress
kubectl apply -f k8s/ingress.yaml
```

**Pros:**
- Auto-scaling (add workers on demand)
- Managed Kubernetes (AWS/GCP/DO handles infrastructure)
- Highly available
- Pay-per-use (cheap at small scale)

**Cons:**
- Complexity (learning curve)
- Cost at scale (can exceed $100/month if not optimized)
- Overkill for <100 users

---

## Option 4: Serverless (AWS Lambda, Google Cloud Functions)

**Time:** 1 hour | **Cost:** Pennies (pay-per-invocation) | **Scale:** Unlimited

### Why Serverless is Hard Here

Nimbus uploads are **long-running** (upload can take hours for 100GB). Serverless has:
- AWS Lambda: 15-minute max timeout
- Google Cloud Functions: 9-minute max timeout
- Azure Functions: 10-minute max timeout

**Not suitable for this use case.** Skip unless you split uploads into smaller chunks (<5GB).

---

## Recommended Path by Use Case

| Use Case | Recommendation | Cost | Setup Time |
|----------|---|---|---|
| **Just trying it** | Local dev (`php -S`) | Free | 5 min |
| **Small team** | Docker Compose on VPS | $10/mo | 30 min |
| **Production MVP** | VPS + Nginx + Let's Encrypt | $15/mo | 1 hour |
| **Scaling to 1000s** | Kubernetes (DigitalOcean) | $40/mo | 2 hours |
| **Enterprise** | AWS EKS + RDS + CloudFront | $100–500/mo | 1 day |

---

## Pre-Ship Checklist

### Security
- [ ] Enable SSL verification in `worker.php` (change line 48, 69, 102, 135, 172 to `true`)
- [ ] Encrypt Google tokens at rest using `libsodium`
- [ ] Set secure session flags in `auth.php`:
  ```php
  session_start([
      'cookie_httponly' => true,
      'cookie_secure' => $_ENV['APP_ENV'] === 'production',
      'cookie_samesite' => 'Strict'
  ]);
  ```
- [ ] Add rate limiting (Redis or in-memory)
- [ ] Enable CSRF tokens on forms
- [ ] Set `X-Content-Type-Options: nosniff` header

### Performance
- [ ] Test with 1000-file upload (check memory usage)
- [ ] Load test: 50 concurrent uploads
- [ ] Monitor worker process startup time
- [ ] Add caching headers to static assets

### Monitoring
- [ ] Set up error logging (Sentry, Rollbar, or Bugsnag)
- [ ] Monitor disk space (database grows with job history)
- [ ] Alert on worker crashes
- [ ] Log all uploads for audit

### Data
- [ ] Backup database daily
- [ ] Encrypt backups
- [ ] Test restore procedure
- [ ] GDPR compliance: add user data export/deletion

### Deployment
- [ ] Test with `.env` secrets, not hardcoded
- [ ] Verify SSL/TLS certificate auto-renewal
- [ ] Test disaster recovery (database corruption)
- [ ] Document rollback procedure

---

## Post-Ship: First Week Ops

### Day 1: Smoke Test
```bash
# Test all flows manually
# 1. Sign in with Google
# 2. Upload 1 file (100MB) to Google Drive
# 3. Upload 1 file to WebDAV
# 4. Check database (jobs table has records)
# 5. Stop process mid-upload, refresh browser, check progress resumes
```

### Day 2–3: Monitor
```bash
# Check logs every few hours
docker logs nimbus-web
docker logs nimbus-worker

# Monitor system resources
top
df -h  # Disk space

# Check database size
ls -lh data/nimbus.db
```

### Day 4–7: Optimize
```bash
# Based on monitoring, adjust:
# 1. Worker count (too many = high CPU; too few = queue builds up)
# 2. Database cleanup (old jobs in DB)
# 3. Rate limits (if needed)
```

---

## Troubleshooting

### "SSL: certificate problem"
```bash
# In worker.php, change:
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

# To (if using self-signed cert):
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_CAINFO, "/etc/ssl/certs/ca-certificates.crt");
```

### "Job stuck in 'processing'"
```bash
# Worker crashed; manually clean up:
sqlite3 data/nimbus.db "UPDATE jobs SET status='error' WHERE status='processing' AND created_at < datetime('now', '-1 hour');"
```

### "Database locked"
```bash
# SQLite issue; switch to PostgreSQL for production:
# See: k8s/postgres-helm-values.yaml
```

### "Upload timeout after 30s"
```bash
# Verify worker.php is running:
docker ps | grep worker
ps aux | grep worker.php

# If not, check logs:
docker logs nimbus-worker
```

---

## Scaling Strategy

### Current (Single VPS)
- **Max throughput:** ~50 concurrent uploads
- **Bottleneck:** CPU (worker.php uses 1 core per upload)

### Next (Multiple Workers)
```bash
# Run 4 workers instead of 1
docker-compose up --scale nimbus-worker=4
# Each worker gets dedicated CPU core
# Max throughput: ~200 concurrent uploads
```

### Future (Kubernetes + Redis)
```bash
# Replace SQLite with Redis for job queue
# K8s auto-scales workers based on queue depth
# Potential bottleneck: cloud provider rate limits (Google Drive, WebDAV)
```

---

## Cost Optimization

| Component | Savings |
|-----------|---------|
| **Use reserved instances** | Save 30–40% vs on-demand (1-year commitment) |
| **Spot instances for workers** | Save 70% for fault-tolerant workloads |
| **Use local SSD for database** | Faster than cloud storage (EBS, Persistent Volumes) |
| **CDN for static assets** | Cloudflare free tier handles images/JS |
| **Auto-scale down at night** | No uploads after 9pm? Zero workers = $0 cost |

---

## Support & Questions

- GitHub Issues: https://github.com/matollaS/original-demo-Nimbus-Cloud-Transfer/issues
- Docs: https://github.com/matollaS/original-demo-Nimbus-Cloud-Transfer/wiki
- Email: support@nimbuscloud.io

---

**Happy shipping!** 🚀
