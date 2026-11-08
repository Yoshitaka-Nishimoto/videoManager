select * from "sessions" where "id" = 'ekQfmNIhUAd2fm3KQvJf9VGu2hp81th277Mjuuvv' limit 1 EloquentUserProvider.php
​
select * from "users" where "id" = 4 limit 1ea55c982.php

select status, count(*) as total from "knowledge_nodes" where ("knowledge_nodes"."owner_id" is null or "knowledge_nodes"."owner_id" = 4) group by "status"DatabaseConversationStore.php#57840μslaravel

​select "conversation_id" from "agent_conversation_messages" where "participant_type" = 'App\Models\User' and "participant_id" = 4 and "agent" = 'App\Ai\Agents\KnowledgeAssistant' order by "id" desc limit 1DatabaseConversationStore.php#67660μslaravel

​select "participant_type", "participant_id" from "agent_conversations" where "id" = '01a19f75-3fb4-736d-ab2c-a01f76e7a569' limit 1KnowledgeChat.php#1071.12mslaravel​
## ---------------------------------------------------------
app\Services\Knowledge\KnowledgeChat.php
## ---------------------------------------------------------
select * from "agent_conversation_messages" where "conversation_id" = '01a19f75-3fb4-736d-ab2c-a01f76e7a569' and "role" in ('user', 'assistant') order by "created_at" asc, "id" asc KnowledgeChat.php#1733.05mslaravel

​select * from "knowledge_nodes" where ("knowledge_nodes"."owner_id" is null or "knowledge_nodes"."owner_id" = 4) and "node_type" = 'concept' and "status" = 'confirmed' and "id" in (396, 418, 417, 420, 609, 618, 612, 628, 636, 613)
ea55c982.php#155800μslaravel​

select count(*) as "aggregate" from "knowledge_nodes" where "status" = 'confirmed' and ("knowledge_nodes"."owner_id" is null or "knowledge_nodes"."owner_id" = 4)ea55c982.php#1554.63mslaravel
​
select "knowledge_nodes".*, (select count(*) from "knowledge_edges" where "knowledge_nodes"."id" = "knowledge_edges"."source_node_id" and ("knowledge_edges"."owner_id" is null or "knowledge_edges"."owner_id" = 4)) as "outgoing_edges_count", (select count(*) from "knowledge_edges" where "knowledge_nodes"."id" = "knowledge_edges"."target_node_id" and ("knowledge_edges"."owner_id" is null or "knowledge_edges"."owner_id" = 4)) as "incoming_edges_count", (select count(*) from "knowledge_sources" where "knowledge_nodes"."id" = "knowledge_sources"."sourceable_id" and "knowledge_sources"."sourceable_type" = 'App\Models\KnowledgeNode' and ("knowledge_sources"."owner_id" is null or "knowledge_sources"."owner_id" = 4)) as "sources_count" from "knowledge_nodes" where "status" = 'confirmed' and ("knowledge_nodes"."owner_id" is null or "knowledge_nodes"."owner_id" = 4) order by case status when 'candidate' then 0 when 'confirmed' then 1 else 2 end, "id" desc limit 20 offset 0 ea55c982.php#1551.81mslaravel

​select * from "videos" where "videos"."id" in (187, 188, 189, 190, 191, 192) and ("videos"."owner_id" is null or "videos"."owner_id" = 4)ea55c982.php#75810μslaravel​

select * from "knowledge_sources" where "knowledge_sources"."sourceable_id" in (618, 619, 620, 625, 626, 627, 628, 632, 636, 638) and "knowledge_sources"."sourceable_type" = 'App\Models\KnowledgeNode' and "video_id" is not null and ("knowledge_sources"."owner_id" is null or "knowledge_sources"."owner_id" = 4)