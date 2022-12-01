<?php

declare(strict_types=1);

namespace Bolt\Storage\Handler;

use Bolt\Entity\Content;
use Bolt\Storage\ContentQueryParser;
use Bolt\Storage\Directive\LimitDirective;
use Bolt\Storage\Directive\PageDirective;
use Bolt\Storage\SelectQuery;
use Doctrine\ORM\Query;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Security;

/**
 *  Handler class to perform select query and return a resultset.
 */
class SelectQueryHandler
{
    /**
     * @var Security|null
     */
    private $security;

    public function __construct(Security $security = null)
    {
        $this->security = $security;
    }

    /**
     * @return Content|Pagerfanta|null
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function __invoke(ContentQueryParser $contentQuery, Security $security = null)
    {

        $repo = $contentQuery->getContentRepository();

        $qb = $repo->getQueryBuilder();

        /** @var SelectQuery $selectQuery */
        $selectQuery = $contentQuery->getService('select');

        // Note: This might seem superfluous, but if we "re-use" $contentQuery,
        // this needs resetting.
        $selectQuery->setSingleFetchMode(null);

        $selectQuery->setQueryBuilder($qb);
        $selectQuery->setContentTypeFilter($contentQuery->getContentTypes());
        $selectQuery->setParameters($contentQuery->getParameters());

        $contentQuery->runScopes($selectQuery);
        // This is required. Not entirely sure why.
        $selectQuery->build();

        // Bolt4 introduces an extra table for field values, so additional
        // joins are required.
        $selectQuery->doReferenceJoins();
        $selectQuery->doTaxonomyJoins();
        $selectQuery->doFieldJoins();

        $contentQuery->runDirectives($selectQuery);
        if (null !== $security && $contentQuery->getContentTypes()[0] === "countries" && $security->isGranted('ROLE_COUNTRY_MANAGER') && !$security->isGranted("ROLE_ADMIN")) {
            if (!$security->isGranted("ROLE_ADMIN") && $security->isGranted("ROLE_COUNTRY_MANAGER") && $contentQuery->getContentTypes()[0] === 'countries') {
                $qb->andWhere('content IN(:countries)')
                    ->setParameter('countries', $security->getUser()->getCountries());
            }
        }
        if (null !== $security && $contentQuery->getContentTypes()[0] === "distributors" && $security->isGranted('ROLE_COUNTRY_MANAGER') && !$security->isGranted("ROLE_ADMIN")) {
            if (!$security->isGranted("ROLE_ADMIN") && $security->isGranted("ROLE_COUNTRY_MANAGER") && $contentQuery->getContentTypes()[0] === 'distributors') {
                $qb->innerJoin('content.relationsFromThisContent', 'rf')
                    ->andWhere('rf.toContent IN(:countries)')
                    ->setParameter('countries', $security->getUser()->getCountries());
            }
        }

        if ($selectQuery->shouldReturnSingle()) {
            if (null !== $security && $security->isGranted('ROLE_COUNTRY_MANAGER') && !$security->isGranted("ROLE_ADMIN") && $contentQuery->getContentTypes()[0] === 'distributors') {
                $qb->innerJoin('content.relationsFromThisContent', 'rf')
                    ->andWhere('rf.toContent IN(:countries)')
                    ->setParameter('countries', $security->getUser()->getCountries());
            }
            return $qb
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        $query = $qb->getQuery();

        $amountPerPage = (int) $contentQuery->getDirective(LimitDirective::NAME);
        $page = $contentQuery->getDirective(PageDirective::NAME);
        if ($page !== null) {
            $page = (int) $page;
        }

        $request = $contentQuery->getRequest();

        return $this->createPaginator($request, $query, $amountPerPage, $page);
    }

    private function createPaginator(?Request $request, Query $query, int $amountPerPage, ?int $page = null): Pagerfanta
    {
        $paginator = new Pagerfanta(new DoctrineORMAdapter($query, true, true));
        $paginator->setMaxPerPage($amountPerPage);

        // If current page was not set explicitly.
        if ($page === null) {
            // If we don't have $request, we're likely not in a web context.
            if ($request) {
                $page = (int) $request->get('page', 1);
            } else {
                $page = 1;
            }
        }

        // If we have multiple pagers on page, we shouldn't allow one of the
        // pagers to go over the maximum, thereby throwing an exception. In this
        // case, this specific pager show stay on the last page.
        $paginator->setCurrentPage(min($page, $paginator->getNbPages()));

        return $paginator;
    }
}
