# frozen_string_literal: true

require 'net/http'
require 'uri'
require 'json'
require 'openssl'

module RedmineSzoSync
  # Nasłuchuje zapisu zagadnienia (UI i REST API przechodzą przez IssuesController)
  # i wysyła sygnał do SZO. Wysyłka jest odporna na błędy — nie może przerwać
  # zapisu zagadnienia w Redmine.
  class Hooks < Redmine::Hook::Listener
    def controller_issues_new_after_save(context = {})
      notify_szo(context[:issue])
    end

    def controller_issues_edit_after_save(context = {})
      notify_szo(context[:issue])
    end

    private

    def notify_szo(issue)
      return unless issue && issue.id

      cfg    = Setting.plugin_redmine_szo_sync || {}
      url    = cfg['szo_url'].to_s.strip
      secret = cfg['secret'].to_s
      return if url.empty? || secret.empty?

      body = { issue_id: issue.id, updated_on: Time.now.utc.iso8601 }.to_json
      sig  = OpenSSL::HMAC.hexdigest('SHA256', secret, body)

      uri  = URI.parse(url)
      http = Net::HTTP.new(uri.host, uri.port)
      http.use_ssl     = (uri.scheme == 'https')
      http.open_timeout = 3
      http.read_timeout = 4

      req = Net::HTTP::Post.new(uri.request_uri,
        'Content-Type'    => 'application/json',
        'X-SZO-Signature' => sig)
      req.body = body
      http.request(req)
    rescue => e
      Rails.logger.warn("[redmine_szo_sync] powiadomienie nieudane: #{e.class}: #{e.message}")
    end
  end
end
