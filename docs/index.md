---
title: Eav
weight: 1
---

# jurager/eav

A Laravel package that adds a flexible Entity-Attribute-Value system to Eloquent models. Attribute schemas are defined once per entity type; values are stored in typed columns and can be scoped per locale.

**Requirements:** PHP ^8.4 · Laravel 11–13

## Contents

- [Installation](installation.md) — Composer, migrations, configuration, model overrides
- [Quickstart](quickstart.md) — Make your first model attributable
- [Reading & Writing Attributes](attributes.md) — Get, set, validate, and batch-persist values
- [Field Types](field-types.md) — Built-in types, flags, validation rules, custom fields
- [Managing Schema](schema.md) — Create, update, delete, and sort attributes, groups, and enums
- [Querying](querying.md) — Filter entities by EAV values using Eloquent scopes
- [Localization](localization.md) — Locales, per-locale values, and translated labels
- [Advanced](advanced.md) — Attribute inheritance, events, Scout search indexing
