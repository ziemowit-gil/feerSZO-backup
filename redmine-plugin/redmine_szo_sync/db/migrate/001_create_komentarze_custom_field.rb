# frozen_string_literal: true
#
# Tworzy pole niestandardowe zagadnień „Komentarze" (Długi tekst), przypięte do
# wszystkich trackerów i widoczne. Jego treść integracja SZO ściąga do zgłoszeń.
# Idempotentne — nie duplikuje pola przy ponownym uruchomieniu.

class CreateKomentarzeCustomField < ActiveRecord::Migration[6.1]
  def up
    return if IssueCustomField.exists?(name: 'Komentarze')

    cf = IssueCustomField.new(
      name:          'Komentarze',
      field_format:  'text',
      is_required:   false,
      visible:       true,   # widoczne dla wszystkich ról (API je zwróci)
      is_filter:     false,
      searchable:    true
    )
    cf.trackers = Tracker.all
    cf.save!
    say "Utworzono pole niestandardowe „Komentarze” (##{cf.id})"
  end

  def down
    IssueCustomField.where(name: 'Komentarze').destroy_all
  end
end
