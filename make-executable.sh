#!/bin/bash

# Make all shell scripts executable
echo "Setting executable permissions for shell scripts..."

chmod +x check-requirements.sh
chmod +x install-enhanced.sh
chmod +x verify-installation.sh
chmod +x install.sh
chmod +x install2.sh

echo "All shell scripts are now executable!"
echo ""
echo "You can now run:"
echo "  ./check-requirements.sh  - Check system requirements"
echo "  ./install-enhanced.sh    - Run the full installation"
echo "  ./verify-installation.sh - Verify installation after completion"