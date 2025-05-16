<?php

declare(strict_types=1);

namespace Ekenbil\CarListing\Parser;

class CarParser
{
    // Add parsing methods here, e.g. parse car details from HTML
    public function parseCarPage($carPage, $carHref, $map): object
    {
        $car = (object) [];
        $car->guid = $carHref;
        $titleNode = $carPage->find('.vehicle-detail-title', 0);
        $car->title = \Ekenbil\CarListing\Helpers\Helpers::cleanText($titleNode ? $titleNode->plaintext : '');
        $priceNode = $carPage->find('#vehicle-details .vehicle-detail-price .car-price-details', 0);
        $car->price = \Ekenbil\CarListing\Helpers\Helpers::cleanNumber($priceNode ? $priceNode->plaintext : '');
        $car->details = $this->extractDetails($carPage, $map);
        $carfaxNode = $carPage->find('#extended-carfax-details .extended-carfax-details-headline a', 0);
        $car->carfax = $carfaxNode ? $carfaxNode->href : '';
        $car->additional = $this->extractAdditional($carPage);
        $car->images = $this->extractImages($carPage);
        $car->features = $this->extractFeatures($carPage);
        return $car;
    }

    private function extractDetails($carPage, $map)
    {
        $details = [];
        $detailsHtml = $carPage->find('div.vehicle-detail-headline .object-info-box dl');
        if ($detailsHtml && count($detailsHtml) > 0) {
            $dtNodes = $detailsHtml[0]->find('dt');
            $ddNodes = $detailsHtml[0]->find('dd');
            foreach ($dtNodes as $i => $dtNode) {
                $keyFeature = trim($dtNode->plaintext);
                $featureKey = $map[$keyFeature] ?? null;
                $ddNode = $ddNodes[$i] ?? null;
                if ($featureKey && $ddNode) {
                    $value = $featureKey[1] === 'number'
                        ? \Ekenbil\CarListing\Helpers\Helpers::cleanNumber($ddNode->plaintext)
                        : \Ekenbil\CarListing\Helpers\Helpers::cleanText($ddNode->plaintext);
                    $details[$featureKey[0]] = [$value, $featureKey[1]];
                }
            }
        }
        return $details;
    }

    private function extractAdditional($carPage)
    {
        $additional = [];
        $key = $value = '';
        $u = 1;
        $carDetailsNodes = $carPage->find('.vehicle-detail-additional-detail > .additional-vehicle-data > ul > li > div');
        foreach ($carDetailsNodes as $carDetails) {
            if ($u % 2 == 0) {
                $value = \Ekenbil\CarListing\Helpers\Helpers::cleanText($carDetails->plaintext);
                $additional[$key] = $value;
            } else {
                $key = \Ekenbil\CarListing\Helpers\Helpers::cleanText($carDetails->plaintext);
            }
            $u++;
        }
        return $additional;
    }

    private function extractImages($carPage)
    {
        $images = [];
        $carImagesNodes = $carPage->find('div.main-slideshow-container > ul.uk-slideshow > li');
        foreach ($carImagesNodes as $carImages) {
            $src = $carImages->{'data-src'} ?? '';
            if (! empty($src)) {
                $images[] = (string) $src;
            }
        }
        return $images;
    }

    private function extractFeatures($carPage)
    {
        $features = [];
        $equipmentNodes = $carPage->find('div.vehicle-detail-equipment-detail .equipment-box ul li');
        foreach ($equipmentNodes as $equipment) {
            $features[] = \Ekenbil\CarListing\Helpers\Helpers::cleanText($equipment->plaintext);
        }
        return $features;
    }
}
