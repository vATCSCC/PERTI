#!/bin/bash
# Azure App Service Startup Script
# Installs ODBC Driver 18 for SQL Server for Python pyodbc

echo "=== Starting custom startup script ==="

# Check if ODBC driver is already installed
if [ -f /opt/microsoft/msodbcsql18/lib64/libmsodbcsql-18.*.so.* ]; then
    echo "ODBC Driver 18 already installed"
else
    echo "Installing ODBC Driver 18 for SQL Server..."

    # Install prerequisites
    apt-get update
    ACCEPT_EULA=Y apt-get install -y curl apt-transport-https gnupg

    # Add Microsoft repository
    curl https://packages.microsoft.com/keys/microsoft.asc | apt-key add -
    curl https://packages.microsoft.com/config/debian/11/prod.list > /etc/apt/sources.list.d/mssql-release.list

    # Install ODBC driver
    apt-get update
    ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev

    echo "ODBC Driver 18 installation complete"
fi

# Verify installation
echo "=== Verifying ODBC installation ==="
odbcinst -q -d

echo "=== Startup script complete ==="
