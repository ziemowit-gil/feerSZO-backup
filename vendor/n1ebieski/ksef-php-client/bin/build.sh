#!/bin/bash

version=$(grep '"version"' composer.json | head -1 | sed -E 's/.*"version": *"([^"]+)".*/\1/')
read -p "The version in composer.json is ${version}. Is that correct? (y/n): " confirm

if [[ "${confirm,,}" == "y" || "${confirm,,}" == "yes" ]]; then
  php bin/console bin/fetch-xsd.php && \
  php bin/console bin/clear-valinor-cache.php && \
  composer cs-fixer && \
  composer rector && \
  composer phpstan && \
  composer pest
else
  echo "Command execution canceled."
fi