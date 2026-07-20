---
title: Eav
weight: 1
---

## Introduction

Package adds a flexible Entity-Attribute-Value system to Eloquent models. You may use it to define an attribute schema once per entity type — `Product`, `Category`, anything that implements the `Attributable` contract — and store values in typed columns that are properly indexed, validated, and scoped per locale.

## Requirements

- PHP 8.4 or higher
- Laravel 11, 12, or 13

## Documentation

- [Installation](installation.md) — Composer, migrations, configuration, model overrides
- [Quickstart](quickstart.md) — make your first model attributable
- [Reading & Writing Attributes](attributes.md) — get, set, validate, and batch-persist values
- [Field Types](field-types.md) — built-in types, flags, validation rules, custom fields
- [Managing Schema](schema.md) — create, update, delete, and sort attributes, groups, and enums
- [Querying](querying.md) — filter entities by EAV values using Eloquent scopes
- [Localization](localization.md) — locales, per-locale values, and translated labels
- [Advanced](advanced.md) — attribute inheritance, events, Scout search indexing
