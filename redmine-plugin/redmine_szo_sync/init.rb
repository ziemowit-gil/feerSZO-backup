# frozen_string_literal: true
#
# redmine_szo_sync — powiadamia SZO (webhook) o zmianach zagadnień.
# Umożliwia natychmiastową synchronizację Helpdesku SZO ↔ Redmine (obok crona po
# stronie SZO). Wysyła tylko sygnał {issue_id} + podpis HMAC — dane SZO pobiera
# z Redmine przez REST API.

Redmine::Plugin.register :redmine_szo_sync do
  name        'SZO Sync'
  author      'FEER'
  description 'Wysyła do SZO powiadomienie webhook po utworzeniu/zmianie zagadnienia (synchronizacja Helpdesku).'
  version     '0.1.0'
  settings default: { 'szo_url' => '', 'secret' => '' },
           partial: 'settings/redmine_szo_sync'
end

require File.expand_path('lib/redmine_szo_sync/hooks', __dir__)
