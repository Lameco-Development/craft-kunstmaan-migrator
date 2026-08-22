<?php

/**
 * The plugin's own translation category.
 *
 * Every string used to be looked up in Craft's `app` category, which does not
 * carry them — it worked only because a missing key falls through to the
 * source text, and it left a project no way to reword the utility.
 *
 * Identity mappings: English is the source language, so this file exists to
 * declare the keys rather than to change them. A translator copies it to
 * `translations/<locale>/kunstmaan-migrator.php` and edits the right-hand side.
 */

return [
    '(fallback)' => '(fallback)',
    'A full run does all of it. The others are for picking up where a run stopped.' => 'A full run does all of it. The others are for picking up where a run stopped.',
    'Adapter' => 'Adapter',
    'Adapters' => 'Adapters',
    'Compile and validate without writing anything to Craft.' => 'Compile and validate without writing anything to Craft.',
    'Could not reach Craft.' => 'Could not reach Craft.',
    'Database' => 'Database',
    'Detected' => 'Detected',
    'Diagnostics' => 'Diagnostics',
    'Doctor answers the same checks as the console command — the install, and every environment the mapping declares. Export writes the state table as NDJSON — the file to diff between runs.' => 'Doctor answers the same checks as the console command — the install, and every environment the mapping declares. Export writes the state table as NDJSON — the file to diff between runs.',
    'Dry run' => 'Dry run',
    'Each adapter writes into a different plugin. Turning one off skips that pass; a plugin that is not installed skips it too, and the run says which.' => 'Each adapter writes into a different plugin. Turning one off skips that pass; a plugin that is not installed skips it too, and the run says which.',
    'Enabled' => 'Enabled',
    'Entries only' => 'Entries only',
    'Environment' => 'Environment',
    'Environments' => 'Environments',
    'Every environment' => 'Every environment',
    'Export state' => 'Export state',
    'Finalize links and media' => 'Finalize links and media',
    'Findings' => 'Findings',
    'Full migration' => 'Full migration',
    'Kunstmaan Migration' => 'Kunstmaan Migration',
    'Legacy database' => 'Legacy database',
    'Locale → site' => 'Locale → site',
    'Mapping file' => 'Mapping file',
    'Must be an environment variable — a value typed here would be committed to project config.' => 'Must be an environment variable — a value typed here would be committed to project config.',
    'Nodes' => 'Nodes',
    'Not migrated by design:' => 'Not migrated by design:',
    'Off by default so an interrupted run resumes cheaply. Turn it on after the payload changed.' => 'Off by default so an interrupted run resumes cheaply. Turn it on after the payload changed.',
    'Pass' => 'Pass',
    'Password' => 'Password',
    'Point at a mapping file and save to see the environments it declares.' => 'Point at a mapping file and save to see the environments it declares.',
    'Port' => 'Port',
    'Preflight' => 'Preflight',
    'Queue migration' => 'Queue migration',
    'Queueing…' => 'Queueing…',
    'Re-save existing entries' => 'Re-save existing entries',
    'Read-only — edit the mapping file to change any of this.' => 'Read-only — edit the mapping file to change any of this.',
    'Ready' => 'Ready',
    'Requires' => 'Requires',
    'Resolve deferred references' => 'Resolve deferred references',
    'Run' => 'Run',
    'Run doctor' => 'Run doctor',
    'Server' => 'Server',
    'Set a mapping file in the plugin settings first.' => 'Set a mapping file in the plugin settings first.',
    "The mapping owns which databases exist, where each one's uploads live, and which legacy locale writes to which Craft site. It is version-controlled next to the field mappings it travels with." => "The mapping owns which databases exist, where each one's uploads live, and which legacy locale writes to which Craft site. It is version-controlled next to the field mappings it travels with.",
    "The run happens on Craft's queue. Keep this tab open, or run craft queue/listen." => "The run happens on Craft's queue. Keep this tab open, or run craft queue/listen.",
    'These fields take an environment variable name, so the values stay in your .env and out of project config. Which database each environment reads comes from the mapping, not from here.' => 'These fields take an environment variable name, so the values stay in your .env and out of project config. Which database each environment reads comes from the mapping, not from here.',
    'This is a production environment. The migrator refuses to run here.' => 'This is a production environment. The migrator refuses to run here.',
    'Upload directories' => 'Upload directories',
    'User' => 'User',
    'built in' => 'built in',
    'not installed' => 'not installed',
    'not migrated' => 'not migrated',
];
