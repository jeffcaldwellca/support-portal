# Docker Deployment Guide

This guide explains how to deploy the Scout Portal using Docker.

## Prerequisites

- Docker Engine 20.10 or higher
- Docker Compose 2.0 or higher
- At least 512MB of available RAM

## Quick Start

### Option 1: Using Docker Hub Image (Recommended)

1. **Copy the environment file:**
   ```bash
   cp .env.example .env
   ```

2. **Edit `.env` with your configuration:**
   - Set your FreeScout API URL and key
   - Configure authentication method (LDAP or local)
   - Update branding information
   - Generate a secure CSRF secret

3. **Update `docker-compose.yml` to use the pre-built image:**
   ```yaml
   services:
     web:
       image: jeffcaldwellca/scout-portal:latest
       # Remove the build section
   ```

4. **Start the container:**
   ```bash
   docker-compose up -d
   ```

5. **Access the application:**
   - Open http://localhost:8080 in your browser

### Option 2: Building from Source

1. **Copy the environment file:**
   ```bash
   cp .env.example .env
   ```

2. **Edit `.env` with your configuration:**
   - Set your FreeScout API URL and key
   - Configure authentication method (LDAP or local)
   - Update branding information
   - Generate a secure CSRF secret

3. **Build and start the container:**
   ```bash
   docker-compose up -d --build
   ```

4. **Access the application:**
   - Open http://localhost:8080 in your browser

## Configuration

### Environment Variables

All configuration is done through the `.env` file. Key settings include:

- **FreeScout Integration:**
  - `FREESCOUT_API_URL`: Your FreeScout instance URL
  - `FREESCOUT_API_KEY`: API key from FreeScout
  - `FREESCOUT_MAILBOX_ID`: Target mailbox ID

- **Authentication:**
  - `ENABLE_LDAP_AUTH`: Set to `true` for LDAP authentication
  - `ENABLE_LOCAL_AUTH`: Set to `true` for local authentication

- **File Uploads:**
  - `UPLOAD_MAX_SIZE`: Maximum file size in bytes (default: 10MB)
  - `UPLOAD_ALLOWED_TYPES`: Comma-separated list of allowed file extensions

### Port Configuration

By default, the application is accessible on port 8080. To change this, edit the `docker-compose.yml` file:

```yaml
ports:
  - "80:80"  # Change 8080 to your desired port
```

## Data Persistence

The following directories are mounted as volumes to persist data:
- `./data` - SQLite database
- `./logs` - Application logs
- `./uploads` - Uploaded files
- `./tmp` - Cache and temporary files

## Management Commands

### View logs:
```bash
docker-compose logs -f
```

### Restart the container:
```bash
docker-compose restart
```

### Stop the container:
```bash
docker-compose down
```

### Rebuild after code changes:
```bash
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### Access container shell:
```bash
docker-compose exec web bash
```

## Local Authentication Setup

If using local authentication, you need to create users:

```bash
docker-compose exec web php bin/manage-local-users.php add <username> <email> <password>
```

## Docker Hub

Pre-built images are available on Docker Hub:
- Repository: `jeffcaldwellca/scout-portal`
- Latest: `jeffcaldwellca/scout-portal:latest`
- Specific versions: `jeffcaldwellca/scout-portal:1.0.0`

Pull the latest image:
```bash
docker pull jeffcaldwellca/scout-portal:latest
```

## Production Deployment

For production environments:

1. **Use the Docker Hub image instead of building:**
   ```yaml
   services:
     web:
       image: jeffcaldwellca/scout-portal:latest
   ```

2. **Use a reverse proxy (nginx/Traefik) for HTTPS:**
   ```yaml
   services:
     web:
       expose:
         - "80"
       # Remove the ports mapping
   ```

2. **Set production environment variables:**
   ```env
   APP_DEBUG=false
   APP_LOG_LEVEL=warning
   ```

3. **Use secrets management for sensitive data** instead of `.env` file

4. **Set up log rotation** for the `./logs` directory

5. **Regular backups** of the `./data` directory

## Troubleshooting

### Permission Issues
If you encounter permission errors:
```bash
sudo chown -R www-data:www-data data logs uploads tmp
```

### Apache not starting
Check logs:
```bash
docker-compose logs web
```

### Cannot connect to FreeScout
- Verify `FREESCOUT_API_URL` is correct
- Ensure the container can reach your FreeScout instance
- Check if API key is valid

### File uploads not working
- Check `uploads/` directory permissions
- Verify `UPLOAD_MAX_SIZE` in `.env`
- Check PHP upload limits in logs

## Health Checks

The container includes a health check that pings the application every 30 seconds. View health status:

```bash
docker ps
```

Look for the health status in the STATUS column.

## Resource Limits

To set resource limits, add to `docker-compose.yml`:

```yaml
services:
  web:
    deploy:
      resources:
        limits:
          cpus: '1.0'
          memory: 512M
        reservations:
          memory: 256M
```

## Security Considerations

- Never commit `.env` to version control
- Use strong passwords for local authentication
- Keep Docker images updated: `docker-compose pull`
- Run behind HTTPS in production
- Regularly backup the database
- Monitor logs for suspicious activity
