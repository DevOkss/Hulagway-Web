<?php

namespace Database\Seeders;

use App\Models\Sdg;
use Illuminate\Database\Seeder;

class SustainableDevelopmentGoalSeeder extends Seeder
{
    public function run(): void
    {
        $goals = [
            ['number' => 1, 'code' => 'SDG_1', 'title' => 'No Poverty', 'short_title' => 'No Poverty', 'color' => '#E5243B', 'description' => 'End poverty in all its forms everywhere'],
            ['number' => 2, 'code' => 'SDG_2', 'title' => 'Zero Hunger', 'short_title' => 'Zero Hunger', 'color' => '#DDA63A', 'description' => 'End hunger, achieve food security and improved nutrition and promote sustainable agriculture'],
            ['number' => 3, 'code' => 'SDG_3', 'title' => 'Good Health and Well-Being', 'short_title' => 'Good Health', 'color' => '#4C9F38', 'description' => 'Ensure healthy lives and promote well-being for all at all ages'],
            ['number' => 4, 'code' => 'SDG_4', 'title' => 'Quality Education', 'short_title' => 'Quality Education', 'color' => '#C5192D', 'description' => 'Ensure inclusive and equitable quality education and promote lifelong learning opportunities for all'],
            ['number' => 5, 'code' => 'SDG_5', 'title' => 'Gender Equality', 'short_title' => 'Gender Equality', 'color' => '#FF3A21', 'description' => 'Achieve gender equality and empower all women and girls'],
            ['number' => 6, 'code' => 'SDG_6', 'title' => 'Clean Water and Sanitation', 'short_title' => 'Clean Water', 'color' => '#26BDE2', 'description' => 'Ensure availability and sustainable management of water and sanitation for all'],
            ['number' => 7, 'code' => 'SDG_7', 'title' => 'Affordable and Clean Energy', 'short_title' => 'Clean Energy', 'color' => '#FCC30B', 'description' => 'Ensure access to affordable, reliable, sustainable and modern energy for all'],
            ['number' => 8, 'code' => 'SDG_8', 'title' => 'Decent Work and Economic Growth', 'short_title' => 'Decent Work', 'color' => '#A21942', 'description' => 'Promote sustained, inclusive and sustainable economic growth, full and productive employment and decent work for all'],
            ['number' => 9, 'code' => 'SDG_9', 'title' => 'Industry, Innovation and Infrastructure', 'short_title' => 'Industry & Innovation', 'color' => '#FD6925', 'description' => 'Build resilient infrastructure, promote inclusive and sustainable industrialization and foster innovation'],
            ['number' => 10, 'code' => 'SDG_10', 'title' => 'Reduced Inequalities', 'short_title' => 'Reduced Inequalities', 'color' => '#DD1367', 'description' => 'Reduce inequality within and among countries'],
            ['number' => 11, 'code' => 'SDG_11', 'title' => 'Sustainable Cities and Communities', 'short_title' => 'Sustainable Cities', 'color' => '#FD9D24', 'description' => 'Make cities and human settlements inclusive, safe, resilient and sustainable'],
            ['number' => 12, 'code' => 'SDG_12', 'title' => 'Responsible Consumption and Production', 'short_title' => 'Responsible Consumption', 'color' => '#BF8B2E', 'description' => 'Ensure sustainable consumption and production patterns'],
            ['number' => 13, 'code' => 'SDG_13', 'title' => 'Climate Action', 'short_title' => 'Climate Action', 'color' => '#3F7E44', 'description' => 'Take urgent action to combat climate change and its impacts'],
            ['number' => 14, 'code' => 'SDG_14', 'title' => 'Life Below Water', 'short_title' => 'Life Below Water', 'color' => '#0A97D9', 'description' => 'Conserve and sustainably use the oceans, seas and marine resources for sustainable development'],
            ['number' => 15, 'code' => 'SDG_15', 'title' => 'Life on Land', 'short_title' => 'Life on Land', 'color' => '#56C02B', 'description' => 'Protect, restore and promote sustainable use of terrestrial ecosystems, sustainably manage forests, combat desertification, and halt and reverse land degradation and halt biodiversity loss'],
            ['number' => 16, 'code' => 'SDG_16', 'title' => 'Peace, Justice and Strong Institutions', 'short_title' => 'Peace & Justice', 'color' => '#00689D', 'description' => 'Promote peaceful and inclusive societies for sustainable development, provide access to justice for all and build effective, accountable and inclusive institutions at all levels'],
            ['number' => 17, 'code' => 'SDG_17', 'title' => 'Partnerships for the Goals', 'short_title' => 'Partnerships', 'color' => '#19486A', 'description' => 'Strengthen the means of implementation and revitalize the Global Partnership for Sustainable Development'],
        ];

        foreach ($goals as $g) {
            $num = str_pad((string) $g['number'], 2, '0', STR_PAD_LEFT);
            Sdg::updateOrCreate(
                ['number' => $g['number']],
                [
                    'code' => $g['code'],
                    'title' => $g['title'],
                    'short_title' => $g['short_title'],
                    'color' => $g['color'],
                    'icon_url' => "/images/sdgs/sdg-{$num}.png",
                    'description' => $g['description'],
                ]
            );
        }
    }
}
