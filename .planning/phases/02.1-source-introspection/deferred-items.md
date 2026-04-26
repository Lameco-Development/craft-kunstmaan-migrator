# Deferred Items — Phase 02.1

Out-of-scope discoveries that the current plan should not fix. Each entry must
include source plan, file, and a one-liner of why we're punting.

## From Plan 07 (analyze wiring)

- **Vendor PHP 8.5 deprecation in `craft\console\Controller::output()`** —
  `vendor/craftcms/cms/src/console/Controller.php:229` implicitly marks
  parameter `$string` as nullable; PHP 8.5 deprecates this. Triggered by our
  new `AnalyzeControllerKbAdapterTest` simply because referencing
  `AnalyzeController::class` autoloads its parent class. Fix lives in upstream
  Craft (or a Composer pin to a patched release); we do not patch vendor.
- **Pre-existing intelephense diagnostic in `LlmClassifier.php:448`** —
  "Undefined method 'post'" reported by static analysis. Per `<phase_context>`
  we leave it untouched in Plan 07 and address it in a dedicated cleanup pass.
