<?php

namespace ExeGeseIT\DoctrineQuerySearchHelper;

use function ctype_print;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\CompositeExpression;
use Doctrine\DBAL\Query\QueryBuilder as QueryBuilderDBAL;
use Doctrine\ORM\Query\Expr\Andx;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\QueryBuilder;
use function mb_strtolower;

/**
 * Description of SearchHelper
 *
 * @author Jean-Claude GLOMBARD <jc.glombard@gmail.com>
 *
 * @phpstan-type TSearchvalue  array<int|string>|bool|int|string
 * @phpstan-type TWhere        array{'expFn': string, 'value': TSearchvalue}
 */
class SearchHelper
{
    private const NULL_VALUE = '_NULL_';

    /**
     * @param array<string|int>|string|int $searched
     * @return string|string[]
     */
    public static function sqlSearchString(string|int|array $searched): string|array
    {
        $_s = [];
        $stack = is_iterable($searched) ? $searched : [$searched];
        foreach ($stack as $searchedValue) {
            //$_s[] = ctype_print($searchedValue) ? '%' . str_replace(['%', '_'], ['\%', '\_'], trim(mb_strtolower((string)$searchedValue))) . '%' : $searchedValue;
            $_s[] = '%' . str_replace(['%', '_'], ['\%', '\_'], trim(\mb_strtolower((string) $searchedValue))) . '%';
        }

        return is_iterable($searched) ? $_s : $_s[0];
    }

    /**
     * @param array<string, TSearchvalue|array<string, TSearchvalue>> $search
     * @param array<string, string> $fields
     */
    public static function setQbSearchClause(QueryBuilder|QueryBuilderDBAL $qb, array $search, array $fields = []): void
    {
        if ($qb instanceof QueryBuilder) {
            self::setQbDQLSearchClause($qb, self::parseSearchParameters($search), $fields);
        } else {
            self::setQbDBALSearchClause($qb, self::parseSearchParameters($search), $fields);
        }
    }

    public static function initializeQbPaginatorOrderby(QueryBuilder|QueryBuilderDBAL $qb, ?string $paginatorSort): void
    {
        if ($qb instanceof QueryBuilder) {
            self::initializeQbPaginatorOrderbyDQL($qb, $paginatorSort ?? '');
        } else {
            self::initializeQbPaginatorOrderbyDBAL($qb, $paginatorSort ?? '');
        }
    }

    public static function initializeQbPaginatorOrderbyDQL(QueryBuilder $qb, string $paginatorSort): void
    {
        if (!empty($paginatorSort)) {
            $_initial_order = $qb->getDQLPart('orderBy');
            $qb->add('orderBy', str_replace('+', ',', $paginatorSort));
            foreach ($_initial_order as $sort) {
                $qb->addOrderBy($sort);
            }
        }
    }



    /** ******************
     *
     *  DBAL Helpers
     *
     ****************** */

    /**
     * @param array<string, TWhere[]|array<string, TWhere[]>> $whereFilters
     * @param array<string, string> $fields
     */
    public static function setQbDBALSearchClause(QueryBuilderDBAL $qb, array $whereFilters, array $fields): void
    {
        foreach ($fields as $searchKey => $field) {
            if (!isset($whereFilters[$searchKey])) {
                continue;
            }

            foreach ($whereFilters[$searchKey] as $index => $criteria) {
                $_searchKey = sprintf('%s_i%d', $searchKey, $index);
                $expFn = $criteria['expFn'];
                $_value = $criteria['value'];

                if (!in_array($expFn, ['in', 'notIn']) && is_array($_value)) {
                    $i = 0;
                    $pattern = array_shift($_value);
                    $parameter = sprintf('%s_%d', $_searchKey, $i++);
                    $orStatements = $qb->expr()->or(
                        $qb
                            ->setParameter($parameter, $pattern)
                            ->expr()->{$expFn}($field, ':' . $parameter)
                    );
                    foreach ($_value as $pattern) {
                        $parameter = sprintf('%s_%d', $_searchKey, $i++);
                        $orStatements = $orStatements->with(
                            $qb
                                ->setParameter($parameter, $pattern)
                                ->expr()->{$expFn}($field, ':' . $parameter)
                        );
                    }
                    $qb->andWhere($orStatements);
                } else {
                    $qb->andWhere($qb->expr()->{$expFn}($field, ':' . $searchKey));

                    if (self::NULL_VALUE !== $_value) {
                        $_typeValue = null;

                        if (is_array($_value)) {
                            $_typeValue = is_int($_value[0]) ? Connection::PARAM_INT_ARRAY : Connection::PARAM_STR_ARRAY;
                        }
                        $qb->setParameter($searchKey, $_value, $_typeValue);
                    }
                }
            }
        }

        //.. AND (field1 ... OR field2 ...)
        if (array_key_exists(SearchFilter::AND_OR, $whereFilters)) {
            $iteration = 0;

            /** @var array<string, TWhere[]> $andORFilters */
            foreach ($whereFilters[SearchFilter::AND_OR] as $andORFilters) {
                $iteration++;

                if (($ANDorStatements = self::getAndOrDBALStatement($qb, $andORFilters, $fields, $iteration)) !== null) {
                    $qb->andWhere($ANDorStatements);
                }
            }
        }

        //.. OR (field1 ... AND field2 ...)
        if (array_key_exists(SearchFilter::OR, $whereFilters)) {
            $iteration = 0;

            /** @var array<string, TWhere[]> $orFilters */
            foreach ($whereFilters[SearchFilter::OR] as $orFilters) {
                $iteration++;

                if (($ORStatements = self::getOrDBALStatement($qb, $orFilters, $fields, $iteration)) !== null) {
                    $qb->orWhere($ORStatements);
                }
            }
        }
    }

    public static function initializeQbPaginatorOrderbyDBAL(QueryBuilderDBAL $qb, string $paginatorSort): void
    {
        if (!empty($paginatorSort)) {
            $_initial_order = $qb->getQueryPart('orderBy');
            $qb->add('orderBy', str_replace('+', ',', $paginatorSort));
            foreach ($_initial_order as $sort) {
                $qb->addOrderBy($sort);
            }
        }
    }

    /**
     * @param array<string, TSearchvalue|array<string, TSearchvalue>> $search
     * @return array<string, TWhere[]|array<string, TWhere[]>>
     */
    private static function parseSearchParameters(array $search): array
    {
        $clauseFilters = [];

        foreach ($search as $ckey => $value) {
            $m = SearchFilter::decodeSearchfilter($ckey);

            $key = $m['key'];
            $filter = SearchFilter::normalize($m['filter']);

            $expFn = null;

            if (is_iterable($value) && in_array($filter, [SearchFilter::OR, SearchFilter::AND_OR])) {
                if (!array_key_exists($filter, $clauseFilters)) {
                    $clauseFilters[$filter] = [];
                }
                $clauseFilters[$filter][] = self::parseSearchParameters($value);
            } elseif (SearchFilter::EQUAL === $filter) {
                $expFn = is_array($value) ? 'in' : 'eq';
            } elseif (SearchFilter::NOT_EQUAL === $filter) {
                $expFn = is_array($value) ? 'notIn' : 'neq';
            } elseif (in_array($filter, [SearchFilter::NULL, SearchFilter::NOT_NULL])) {
                $expFn = '_' === $filter ? 'isNull' : 'isNotNull';
                $value = self::NULL_VALUE;
            } elseif (!empty($value)) {
                switch ($filter) {
                    case SearchFilter::LOWER:
                        $expFn = 'lt';
                        break;

                    case SearchFilter::LOWER_OR_EQUAL:
                        $expFn = 'lte';
                        break;

                    case SearchFilter::GREATER:
                        $expFn = 'gt';
                        break;

                    case SearchFilter::GREATER_OR_EQUAL:
                        $expFn = 'gte';
                        break;

                    case SearchFilter::LIKE:
                        $expFn = 'like';
                        $value = self::sqlSearchString($value);
                        break;

                    case SearchFilter::NOT_LIKE:
                        $expFn = 'notLike';
                        $value = self::sqlSearchString($value);
                        break;

                    default:
                        $expFn = is_array($value) ? 'in' : 'eq';
                        break;
                }
            }

            if (null !== $expFn) {
                if (!array_key_exists($key, $clauseFilters)) {
                    $clauseFilters[$key] = [];
                }

                $clauseFilters[$key][] = [
                    'expFn' => $expFn,
                    'value' => $value,
                ];
            }
        }

        return $clauseFilters;
    }




    /** ******************
     *
     *  DQL Helpers
     *
     ****************** */

    /**
     * @param array<string, TWhere[]|array<string, TWhere[]>> $whereFilters
     * @param array<string, string> $fields
     */
    private static function setQbDQLSearchClause(QueryBuilder $qb, array $whereFilters, array $fields): void
    {
        foreach ($fields as $searchKey => $field) {
            if (!isset($whereFilters[$searchKey])) {
                continue;
            }

            foreach ($whereFilters[$searchKey] as $index => $criteria) {
                $_searchKey = sprintf('%s_i%d', $searchKey, $index);
                $expFn = $criteria['expFn'];
                $_value = $criteria['value'];

                if (!in_array($expFn, ['in', 'notIn']) && is_array($_value)) {
                    $i = 0;
                    $orStatements = $qb->expr()->orX();
                    foreach ($_value as $pattern) {
                        $parameter = sprintf('%s_%d', $_searchKey, $i++);
                        $orStatements->add(
                            $qb
                                ->setParameter($parameter, $pattern)
                                ->expr()->{$expFn}($field, ':' . $parameter)
                        );
                    }
                    $qb->andWhere($orStatements);
                } else {
                    $qb->andWhere($qb->expr()->{$expFn}($field, ':' . $_searchKey));

                    if (self::NULL_VALUE !== $_value) {
                        $qb->setParameter($_searchKey, $_value);
                    }
                }
            }
        }

        //.. AND (field1 ... OR field2 ...)
        if (array_key_exists(SearchFilter::AND_OR, $whereFilters)) {
            $iteration = 0;

            /** @var array<string, TWhere[]> $andORFilters */
            foreach ($whereFilters[SearchFilter::AND_OR] as $andORFilters) {
                $iteration++;

                if (($ANDorStatements = self::getAndOrDQLStatement($qb, $andORFilters, $fields, $iteration)) !== null) {
                    $qb->andWhere($ANDorStatements);
                }
            }
        }

        //.. OR (field1 ... AND field2 ...)
        if (array_key_exists(SearchFilter::OR, $whereFilters)) {
            $iteration = 0;

            /** @var array<string, TWhere[]> $orFilters */
            foreach ($whereFilters[SearchFilter::OR] as $orFilters) {
                $iteration++;

                if (($ORStatements = self::getOrDQLStatement($qb, $orFilters, $fields, $iteration)) !== null) {
                    $qb->orWhere($ORStatements);
                }
            }
        }
    }

    /**
     * @param array<string, TWhere[]> $andORFilters
     * @param array<string, string> $fields
     */
    private static function getAndOrDQLStatement(QueryBuilder $qb, array $andORFilters, array $fields, int $iteration): ?Orx
    {
        $ANDorStatements = null;
        foreach ($fields as $searchKey => $field) {
            if (!isset($andORFilters[$searchKey])) {
                continue;
            }

            $ANDorStatements ??= $qb->expr()->orX();

            foreach ($andORFilters[$searchKey] as $index => $criteria) {
                $_searchKey = sprintf('andor%d_%s_i%d', $iteration, $searchKey, $index);
                $expFn = $criteria['expFn'];
                $_value = $criteria['value'];

                if (!in_array($expFn, ['in', 'notIn']) && is_array($_value)) {
                    $i = 0;
                    $orStatements = $qb->expr()->orX();
                    foreach ($_value as $pattern) {
                        $parameter = sprintf('%s_%d', $_searchKey, $i++);
                        $orStatements->add(
                            $qb
                                ->setParameter($parameter, $pattern)
                                ->expr()->{$expFn}($field, ':' . $parameter)
                        );
                    }
                    $ANDorStatements->add($orStatements);
                } else {
                    $ANDorStatements->add(
                        $qb->expr()->{$expFn}($field, ':' . $_searchKey)
                    );

                    if (self::NULL_VALUE !== $_value) {
                        $qb->setParameter($_searchKey, $_value);
                    }
                }
            }
        }

        return $ANDorStatements;
    }

    /**
     * @param array<string, TWhere[]> $orFilters
     * @param array<string, string> $fields
     */
    private static function getOrDQLStatement(QueryBuilder $qb, array $orFilters, array $fields, int $iteration): ?Andx
    {
        $ORStatements = null;
        foreach ($fields as $searchKey => $field) {
            if (!isset($orFilters[$searchKey])) {
                continue;
            }

            $ORStatements ??= $qb->expr()->andX();

            foreach ($orFilters[$searchKey] as $index => $criteria) {
                $_searchKey = sprintf('or%d_%s_i%d', $iteration, $searchKey, $index);
                $expFn = $criteria['expFn'];
                $_value = $criteria['value'];

                if (!in_array($expFn, ['in', 'notIn']) && is_array($_value)) {
                    $i = 0;
                    $orStatements = $qb->expr()->orX();
                    foreach ($_value as $pattern) {
                        $parameter = sprintf('%s_%d', $_searchKey, $i++);
                        $orStatements->add(
                            $qb
                                ->setParameter($parameter, $pattern)
                                ->expr()->{$expFn}($field, ':' . $parameter)
                        );
                    }
                    $ORStatements->add($orStatements);
                } else {
                    $ORStatements->add(
                        $qb->expr()->{$expFn}($field, ':' . $_searchKey)
                    );

                    if (self::NULL_VALUE !== $_value) {
                        $qb->setParameter($_searchKey, $_value);
                    }
                }
            }
        }

        return $ORStatements;
    }

    /**
     * @param array<string, TWhere[]> $andORFilters
     * @param array<string, string> $fields
     */
    private static function getAndOrDBALStatement(QueryBuilderDBAL $qb, array $andORFilters, array $fields, int $iteration): ?CompositeExpression
    {
        $ANDorStatements = null;
        foreach ($fields as $searchKey => $field) {
            if (!isset($andORFilters[$searchKey])) {
                continue;
            }

            $ANDorStatements ??= $qb->expr()->or();

            foreach ($andORFilters[$searchKey] as $index => $criteria) {
                $_searchKey = sprintf('andor%d_%s_i%d', $iteration, $searchKey, $index);
                $expFn = $criteria['expFn'];
                $_value = $criteria['value'];

                if (!in_array($expFn, ['in', 'notIn']) && is_array($_value)) {
                    $i = 0;
                    $pattern = array_shift($_value);
                    $parameter = sprintf('%s_%d', $_searchKey, $i++);
                    $orStatements = $qb->expr()->or(
                        $qb
                            ->setParameter($parameter, $pattern)
                            ->expr()->{$expFn}($field, ':' . $parameter)
                    );
                    foreach ($_value as $pattern) {
                        $parameter = sprintf('%s_%d', $_searchKey, $i++);
                        $orStatements = $orStatements->with(
                            $qb
                                ->setParameter($parameter, $pattern)
                                ->expr()->{$expFn}($field, ':' . $parameter)
                        );
                    }
                    $ANDorStatements->add($orStatements);
                } else {
                    $ANDorStatements->add(
                        $qb->expr()->{$expFn}($field, ':' . $_searchKey)
                    );

                    if (self::NULL_VALUE !== $_value) {
                        $_typeValue = null;

                        if (is_array($_value)) {
                            $_typeValue = is_int($_value[0]) ? Connection::PARAM_INT_ARRAY : Connection::PARAM_STR_ARRAY;
                        }
                        $qb->setParameter($_searchKey, $_value, $_typeValue);
                    }
                }
            }
        }

        return $ANDorStatements;
    }

    /**
     * @param array<string, TWhere[]> $orFilters
     * @param array<string, string> $fields
     */
    private static function getOrDBALStatement(QueryBuilderDBAL $qb, array $orFilters, array $fields, int $iteration): ?CompositeExpression
    {
        $ORStatements = null;
        foreach ($fields as $searchKey => $field) {
            if (!isset($orFilters[$searchKey])) {
                continue;
            }

            $ORStatements ??= $qb->expr()->and();

            foreach ($orFilters[$searchKey] as $index => $criteria) {
                $_searchKey = sprintf('or%d_%s_i%d', $iteration, $searchKey, $index);
                $expFn = $criteria['expFn'];
                $_value = $criteria['value'];

                if (!in_array($expFn, ['in', 'notIn']) && is_array($_value)) {
                    $i = 0;
                    $pattern = array_shift($_value);
                    $parameter = sprintf('%s_%d', $_searchKey, $i++);
                    $orStatements = $qb->expr()->or(
                        $qb
                            ->setParameter($parameter, $pattern)
                            ->expr()->{$expFn}($field, ':' . $parameter)
                    );
                    foreach ($_value as $pattern) {
                        $parameter = sprintf('%s_%d', $_searchKey, $i++);
                        $orStatements = $orStatements->with(
                            $qb
                                ->setParameter($parameter, $pattern)
                                ->expr()->{$expFn}($field, ':' . $parameter)
                        );
                    }
                    $ORStatements->add($orStatements);
                } else {
                    $ORStatements->add(
                        $qb->expr()->{$expFn}($field, ':' . $_searchKey)
                    );

                    if (self::NULL_VALUE !== $_value) {
                        $_typeValue = null;

                        if (is_array($_value)) {
                            $_typeValue = is_int($_value[0]) ? Connection::PARAM_INT_ARRAY : Connection::PARAM_STR_ARRAY;
                        }
                        $qb->setParameter($_searchKey, $_value, $_typeValue);
                    }
                }
            }
        }

        return $ORStatements;
    }
}
