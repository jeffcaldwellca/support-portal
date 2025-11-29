#!/bin/bash

# Quick Setup Script for Local Authentication
# This script helps you enable local authentication and create your first local admin user

set -e

echo "========================================"
echo "Local Authentication Quick Setup"
echo "========================================"
echo ""

# Check if .env exists
if [ ! -f .env ]; then
    echo "Error: .env file not found"
    echo "Please copy .env.example to .env first:"
    echo "  cp .env.example .env"
    exit 1
fi

# Check if local auth is already enabled
if grep -q "ENABLE_LOCAL_AUTH=true" .env; then
    echo "✓ Local authentication is already enabled"
else
    echo "Enabling local authentication..."
    
    # Add ENABLE_LOCAL_AUTH if it doesn't exist
    if grep -q "ENABLE_LOCAL_AUTH=" .env; then
        # Update existing value
        sed -i.bak 's/ENABLE_LOCAL_AUTH=.*/ENABLE_LOCAL_AUTH=true/' .env
    else
        # Add new line
        echo "" >> .env
        echo "# Local Authentication" >> .env
        echo "ENABLE_LOCAL_AUTH=true" >> .env
    fi
    
    echo "✓ Local authentication enabled"
fi

echo ""
echo "Now let's create your first local admin user"
echo "--------------------------------------------"
echo ""

# Prompt for user details
read -p "Username: " username
read -p "Email: " email
read -p "Full Name: " fullname

echo ""
echo "Creating user account..."
php bin/manage-local-users.php create "$username" "$email" "$fullname" "IT Department" "Administrator"

echo ""
echo "========================================"
echo "Setup Complete!"
echo "========================================"
echo ""
echo "You can now:"
echo "  1. Log in with username: $username"
echo "  2. Manage users with: php bin/manage-local-users.php list"
echo "  3. View documentation: docs/LOCAL_AUTHENTICATION.md"
echo ""
echo "If you want to disable LDAP and use only local auth, update your .env:"
echo "  ENABLE_LDAP_AUTH=false"
echo ""
