# 03. Audit log

The audit log records who changed what through the panel.

## Masking sensitive fields

For sensitive fields the audit log records only that the field changed. Mark a field `->sensitive()`
to mask it; every `Password` field is sensitive.

## Pruning

Old entries are pruned by the scheduled command.
