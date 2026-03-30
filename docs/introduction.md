---
title: Introduction
weight: 10
---

# Introduction

`jurager/eav` is a Laravel package that adds a flexible Entity-Attribute-Value system to Eloquent models.

## What You Get

- **Typed storage** — values are stored in dedicated typed columns (`value_text`, `value_integer`, `value_float`, `value_boolean`, `value_date`, `value_datetime`).
- **Localization** — attribute values can be stored per locale.
- **Attribute inheritance** — nested entities (e.g. a category tree) can inherit attribute schemas from ancestors.
- **Field registry** — field types are pluggable; domain-specific types are registered in the application config.
- **Query scopes** — filter entities by EAV values using familiar Eloquent syntax.
- **Search sync** — background jobs keep a search index up to date when attributes change.

## Requirements

- PHP ^8.4
- Laravel 11–13

## How It Works

The package separates *schema* from *values*:

- **Schema** — `attributes` table defines what attributes exist for an entity type (e.g. `product.color`).
- **Values** — `entity_attribute` table stores the actual values per entity instance.

Any Eloquent model can become *attributable* by implementing the `Attributable` contract and using the `HasAttributes` trait.
