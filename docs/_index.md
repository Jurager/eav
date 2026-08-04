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

- [Getting Started](getting-started.md) — Installation, configuration, and a quick tour.
- [Reading & Writing Attributes](attributes.md) — Get, set, validate, and batch-persist values.
- [Field Types](field-types.md) — Built-in types, flags, validation rules, and custom fields.
- [Managing Schema](schema.md) — Create and manage attributes, groups, and enums.
- [Querying](querying.md) — Filter entities by EAV values using Eloquent scopes.
- [Localization](localization.md) — Locales, per-locale values, and translated labels.
- [Advanced](advanced.md) — Attribute inheritance, events, and Scout search indexing.
