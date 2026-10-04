<?php

namespace Database\Seeders;

use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use Illuminate\Database\Seeder;

class KnowledgeRelationTypeSeeder extends Seeder
{
    /**
     * Seed the relation types that edges are allowed to use.
     *
     * Edges read "source → label → target".
     */
    public function run(): void
    {
        $knowledge = [KnowledgeNode::TYPE_CONCEPT, KnowledgeNode::TYPE_DECISION, KnowledgeNode::TYPE_IMPLEMENTATION];

        $types = [
            [
                'key' => 'extracted_from',
                'label' => '分析から抽出した',
                'inverse_label' => '抽出された知識',
                'description' => '概念・設計判断などが、どの分析から抽出されたかを示す。',
                'allowed_source_types' => $knowledge,
                'allowed_target_types' => [KnowledgeNode::TYPE_ANALYSIS],
            ],
            [
                'key' => 'analyzes',
                'label' => '分析対象とする',
                'inverse_label' => '分析された',
                'description' => '分析ノードと元動画ノードをつなぐ。',
                'allowed_source_types' => [KnowledgeNode::TYPE_ANALYSIS],
                'allowed_target_types' => [KnowledgeNode::TYPE_VIDEO],
            ],
            [
                'key' => 'evidences',
                'label' => '根拠となる',
                'inverse_label' => '根拠を持つ',
                'description' => '動画・分析・概念が、別の知識の根拠になっていることを示す。',
                'allowed_source_types' => [KnowledgeNode::TYPE_VIDEO, KnowledgeNode::TYPE_ANALYSIS, KnowledgeNode::TYPE_CONCEPT],
                'allowed_target_types' => [KnowledgeNode::TYPE_CONCEPT, KnowledgeNode::TYPE_DECISION],
            ],
            [
                'key' => 'implements',
                'label' => '実装する',
                'inverse_label' => '実装される',
                'description' => '実装ノードが、どの概念・設計判断を実装しているかを示す。',
                'allowed_source_types' => [KnowledgeNode::TYPE_IMPLEMENTATION],
                'allowed_target_types' => [KnowledgeNode::TYPE_CONCEPT, KnowledgeNode::TYPE_DECISION],
            ],
            [
                'key' => 'prerequisite_of',
                'label' => '前提となる',
                'inverse_label' => '前提とする',
                'description' => 'ある知識が、別の知識の前提であることを示す。循環は禁止。',
                'allowed_source_types' => $knowledge,
                'allowed_target_types' => $knowledge,
            ],
            [
                'key' => 'related_to',
                'label' => '関連する',
                'inverse_label' => '関連する',
                'description' => '向きを持たない緩い関連。循環を許可する。',
                'allow_cycle' => true,
                'allowed_source_types' => $knowledge,
                'allowed_target_types' => $knowledge,
            ],
        ];

        foreach ($types as $type) {
            KnowledgeRelationType::query()->updateOrCreate(
                ['key' => $type['key']],
                ['allow_cycle' => false, ...$type],
            );
        }
    }
}
