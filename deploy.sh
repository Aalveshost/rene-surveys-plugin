#!/bin/bash
# ─────────────────────────────────────────────
#  FormSync Excel WP — Deploy Script
#  Incrementa versão patch e faz push para o GitHub (WP Pusher)
# ─────────────────────────────────────────────

PLUGIN_FILE="formsync-excel-wp.php"

# 1. Lê a versão atual
CURRENT=$(grep -E "^\s*\* Version:" "$PLUGIN_FILE" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+")
echo "📦 Versão atual: $CURRENT"

# 2. Incrementa o patch (ex: 1.0.1 → 1.0.2)
IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT"
PATCH=$((PATCH + 1))
NEW_VERSION="$MAJOR.$MINOR.$PATCH"
echo "🚀 Nova versão: $NEW_VERSION"

# 3. Atualiza no arquivo PHP
sed -i '' "s/* Version: $CURRENT/* Version: $NEW_VERSION/" "$PLUGIN_FILE"

# 4. Commit e push
git add -A

# Usa mensagem customizada se passada como argumento, senão usa padrão
MSG="${1:-deploy: v$NEW_VERSION}"
git commit -m "$MSG (v$NEW_VERSION)"
git push origin main

echo "✅ Deploy concluído — versão $NEW_VERSION publicada!"
