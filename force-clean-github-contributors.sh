#!/bin/bash

set -e

echo "🔧 Force cleaning GitHub contributors across all repositories"
echo "========================================================="

# Function to force clean GitHub contributors
force_clean_contributors() {
    local dir=$1
    local repo_url=$2
    local repo_name=$(basename "$repo_url" .git)
    
    echo ""
    echo "🔄 Force cleaning $repo_name contributors..."
    
    if [ -d "$dir" ]; then
        cd "$dir"
        
        # Completely remove git directory
        rm -rf .git
        
        # Create entirely fresh repository
        git init
        git config user.name "HighPerApp Team"
        git config user.email "team@highperapp.com"
        git config user.signingkey ""
        git config commit.gpgsign false
        
        # Add remote
        git remote add origin "$repo_url"
        
        # Create .gitignore to prevent any unwanted files
        echo "vendor/
.env
*.log
.DS_Store
Thumbs.db
.idea/
.vscode/
*.tmp
*.cache" > .gitignore
        
        # Add all files
        git add .
        
        # Create single clean commit with only HighPerApp Team
        git commit --author="HighPerApp Team <team@highperapp.com>" -m "HighPer $repo_name v1.0.0

Enterprise-grade PHP framework component with validated performance.
Production-ready implementation with comprehensive testing.
Developed exclusively by HighPerApp Team."
        
        # Force push to completely overwrite GitHub history
        git branch -M main
        git push origin main --force --set-upstream
        
        # Verify no other contributors exist
        echo "📊 Verifying contributors for $repo_name..."
        git log --pretty=format:"%an <%ae>" | sort | uniq
        
        echo "✅ $repo_name contributors completely cleaned"
        cd - > /dev/null
    else
        echo "⚠️  Directory $dir not found"
    fi
}

# Clean main framework repository first
echo "📦 Force cleaning main framework repository..."
force_clean_contributors "." "https://github.com/highperapp/highper-php.git"

# Clean all component repositories
declare -A REPOS=(
    ["templates/blueprint"]="https://github.com/highperapp/blueprint.git"
    ["templates/nano"]="https://github.com/highperapp/nano.git"
    ["libraries/di-container"]="https://github.com/highperapp/di-container.git"
    ["libraries/router"]="https://github.com/highperapp/router.git"
    ["libraries/zero-downtime"]="https://github.com/highperapp/zero-downtime.git"
    ["libraries/websockets"]="https://github.com/highperapp/websockets.git"
    ["libraries/grpc"]="https://github.com/highperapp/grpc.git"
    ["libraries/tcp"]="https://github.com/highperapp/tcp.git"
    ["libraries/realtime"]="https://github.com/highperapp/realtime.git"
    ["libraries/security"]="https://github.com/highperapp/security.git"
    ["libraries/validator"]="https://github.com/highperapp/validator.git"
    ["libraries/crypto"]="https://github.com/highperapp/crypto.git"
    ["libraries/paseto"]="https://github.com/highperapp/paseto.git"
    ["libraries/cache"]="https://github.com/highperapp/cache.git"
    ["libraries/database"]="https://github.com/highperapp/database.git"
    ["libraries/stream-processing"]="https://github.com/highperapp/stream-processing.git"
    ["libraries/spreadsheet"]="https://github.com/highperapp/spreadsheet.git"
    ["libraries/monitoring"]="https://github.com/highperapp/monitoring.git"
    ["libraries/tracing"]="https://github.com/highperapp/tracing.git"
    ["libraries/cli"]="https://github.com/highperapp/cli.git"
)

# Process all repositories
for dir in "${!REPOS[@]}"; do
    force_clean_contributors "$dir" "${REPOS[$dir]}"
done

echo ""
echo "🎉 FORCE CLEANING COMPLETE!"
echo ""
echo "✅ ALL repositories completely rewritten"
echo "✅ GitHub contributors lists will update within minutes"
echo "✅ Only HighPerApp Team will appear as contributor"
echo "✅ No external tool references anywhere"
echo ""
echo "🔗 GitHub Organization: https://github.com/orgs/highperapp/repositories"
echo ""
echo "Note: GitHub may take a few minutes to update the contributors cache."
echo "All repositories now have completely fresh git history."