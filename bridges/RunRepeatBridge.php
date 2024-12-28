<?php

use Facebook\WebDriver\Exception\InvalidSessionIdException;
use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\Exception\Internal\WebDriverCurlException;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

class RunRepeatBridge extends WebDriverAbstract
{
    const MAINTAINER = 'swofl';
    const NAME = 'RunRepeat';
    const CACHE_TIMEOUT = 6 * 60 * 60; // 6h;
    const URI = 'https://runrepeat.com';
    const DESCRIPTION = 'Bridge for Running Shoe Review site RunRepeat';
    const PARAMETERS = [ [
        'qLimit' => [
            'name' => 'Query Limit',
            'title' => 'Amount of articles to query',
            'type' => 'number',
            'defaultValue' => 3,
        ],
        'shoeType' => [
            'name' => 'Shoe Type',
            'title' => 'Type of shoe to query',
            'type' => 'list',
            'values' => [
                'Basketball Shoes' => 'basketball-shoes',
                'Cross Country Shoes' => 'cross-country-shoes',
                'Hiking Boots' => 'hiking-boots',
                'Hiking Sandals' => 'hiking-sandals',
                'Hiking Shoes' => 'hiking-shoes',
                'Running Shoes' => 'running-shoes',
                'Sneakers' => 'sneakers',
                'Tennis Shoes' => 'tennis-shoes',
                'Track Spikes' => 'track-spikes',
                'Training Shoes' => 'training-shoes',
                'Walking Shoes' => 'walking-shoes'
            ],
            'defaultValue' => 'running-shoes'
        ]
    ] ];
    const ARTICLE_CACHE_TTL = 60 * 60 * 24 * 7; // 1 week

    protected function getOuterHtmlIfNoImg(simple_html_dom_node $element)
    {
        if (count($element->find('img')) === 0 || $element->tag === 'ul') {
            return $element;
        } else {
            return '';
        }
    }

    protected function clickCookieBanner()
    {
        $this->getDriver()->wait()->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::xpath('//div[contains(@class, "amc-modal-container")]')));
        $cookieContainer = $this->getDriver()->findElement(WebDriverBy::xpath('//div[contains(@class, "amc-modal-container")]'));
        $acceptButton = $cookieContainer->findElement(WebDriverBy::xpath('.//div[@format = "primary"]'));
        $acceptButton->click();
        $this->getDriver()->wait()->until(WebDriverExpectedCondition::invisibilityOfElementLocated(WebDriverBy::xpath('//div[contains(@class, "amc-modal-container")]')));
    }

    protected function setFilterToLatest()
    {
        $this->getDriver()->wait()->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::xpath('//div[contains(@class, "product-list-header")]//div[contains(@class, "list-sorting") and contains(@class, "hidden-xs")]//button')));
        $filterButton = $this->getDriver()->findElement(WebDriverBy::xpath('//div[contains(@class, "product-list-header")]//div[contains(@class, "list-sorting") and contains(@class, "hidden-xs")]//button'));
        $filterButton->click();
        $this->getDriver()->wait()->until(WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::xpath('//div[contains(@class, "product-list-header")]//div[contains(@class, "list-sorting") and contains(@class, "hidden-xs")]//ul')));
        $newestButton = $this->getDriver()->findElement(WebDriverBy::xpath('//div[contains(@class, "product-list-header")]//div[contains(@class, "list-sorting") and contains(@class, "hidden-xs")]//ul/li[@id="dropdown-select-option-newest"]'));
        $newestButton->click();
        $this->getDriver()->wait()->until(WebDriverExpectedCondition::invisibilityOfElementLocated(WebDriverBy::xpath('//div[contains(@class, "product-list-header")]//div[contains(@class, "list-sorting") and contains(@class, "hidden-xs")]//ul')));
        usleep(500000); // wait 0.5s for the page to reload
    }

    protected function parseArticlePropertiesFromListEntry(RemoteWebElement $product)
    {
        $result = [];

        $heading = $product->findElement(WebDriverBy::xpath('.//div[contains(@class, "product-name")]/a'));
        $result['title'] = $heading->findElement(WebDriverBy::tagName('span'))->getText();
        $result['uri'] = self::URI . $heading->getAttribute('href');
        $result['uid'] = hash('sha256', $result['title']);

        return $result;
    }

    protected function parseFullArticle($item)
    {
        $article = getSimpleHTMLDOMCached($item['uri']);

        $articleInfo = $article->find('div.author-name', 0)->text();
        $articleInfoParts = explode(' on ', $articleInfo);
        $item['author'] = $articleInfoParts[0];
        $item['timestamp'] = strtotime($articleInfoParts[1] . ' 13:37');

        $mainImage = $article->find('div.top-section-container div.main-image>img', 0);
        $feedImage = '<img src="' . $mainImage->src . '" alt="' . $mainImage->alt . '">';

        $articleContent = $article->find('article.shoe-review', 0);
        $contentTopSection = $articleContent->find('div.top-section-content', 0);
        
        $content = '';

        $content .= '<p>' . $contentTopSection->find('section#product-intro>div', 0)->innertext . '</p>';

        $content .= '<h2>Pros</h2>';
        $content .= $contentTopSection->find('div#the_good>ul', 0)->outertext();

        $content .= '<h2>Cons</h2>';
        $content .= $contentTopSection->find('div#the_bad>ul', 0)->outertext();

        $contentLab = $articleContent->find('div.lab-content', 0);

        $content .= '<h2>Who should buy</h2>';
        $contentWhoShouldBuyElements = $contentLab->find('#who-should-buy .rr_section_content p, #who-should-buy .rr_section_content ul');
        foreach ($contentWhoShouldBuyElements as $element) {
            $content .= $this->getOuterHtmlIfNoImg($element);
        }

        $content .= '<h2>Who should NOT buy</h2>';
        $contentWhoShouldNotBuyElements = $contentLab->find('#who-should-not-buy .rr_section_content p, #who-should-not-buy .rr_section_content ul');
        foreach ($contentWhoShouldNotBuyElements as $element) {
            $content .= $this->getOuterHtmlIfNoImg($element);
        }

        $item['content'] = $feedImage . str_replace("\n", '', $content);

        return $item;
    }

    /**
     * Puts the content of the first page into the $items array.
     *
     * @throws Facebook\WebDriver\Exception\InvalidSessionIdException
     * @throws Facebook\WebDriver\Exception\NoSuchElementException
     * @throws Facebook\WebDriver\Exception\TimeoutException
     */
    public function collectData()
    {
        parent::collectData();

        try {
            $this->getDriver()->get(self::URI . '/catalog/' . $this->getInput('shoeType'));

            $this->clickCookieBanner();
            $this->setFilterToLatest();

            $queryLimit = (int) $this->getInput('qLimit');
            if ($queryLimit > 30) {
                $queryLimit = 30;
            }

            $this->getDriver()->wait()->until(WebDriverExpectedCondition::visibilityOfElementLocated(
                WebDriverBy::xpath('//div[contains(@class, "reviews")]//div[@class="filter_shoes"]//li[contains(@class, "product_list")][' . $queryLimit . ']')
            ));
            $this->setIcon($this->getDriver()->findElement(WebDriverBy::xpath('//link[@rel="shortcut icon"]'))->getAttribute('href'));

            $articles = [];

            $products = $this->getDriver()->findElements(WebDriverBy::xpath('//div[contains(@class, "reviews")]//div[@class="filter_shoes"]//li[contains(@class, "product_list")]'));

            for ($i = 0; $i < $queryLimit; $i++) {
                array_push($articles, $this->parseArticlePropertiesFromListEntry($products[$i]));
            }

            foreach ($articles as $article) {
                $fullarticle = $this->cache->get($article['uid']);

                if ($fullarticle === null) {
                    $fullarticle = $this->parseFullArticle($article);
                    $this->cache->set($article['uid'], $fullarticle, self::ARTICLE_CACHE_TTL);
                }

                $this->items[] = $fullarticle;
            }
        } catch (WebDriverException | WebDriverCurlException $e) {
            $this->logger->warning('Could not collect data: ' . $e->getMessage());
        } finally {
            try {
                $this->cleanUp();
            } catch (InvalidSessionIdException | WebDriverException $e) {
                $this->logger->warning('Could not clean up WebDriver: ' . $e->getMessage());
            }
        }
    }
}