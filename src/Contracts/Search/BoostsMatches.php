<?php

declare(strict_types=1);

namespace Jurager\Eav\Contracts\Search;

/**
 * An engine that applies the boosts of a search by itself when it ranks the hits.
 *
 * Without it, the boosted conditions are searched one by one and the hits ranked by weighted score.
 */
interface BoostsMatches {}
