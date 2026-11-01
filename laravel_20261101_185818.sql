[graphify extract] found 0 code, 0 docs, 0 papers, 0 images
[graphify extract] introspecting PostgreSQL schema...
[graphify extract] PostgreSQL: 122 nodes, 168 edges
[graphify] backed up curated graph (4 files) -> 2026-11-01/
[graphify extract] wrote /home/user/video_manager/graphify-out/postgres/graph.json: 122 nodes, 162 edges, 3 communities
[graphify extract] wrote /home/user/video_manager/graphify-out/postgres/.graphify_analysis.json
[graphify extract] next: run `graphify cluster-only /home/user/video_manager/graphify-out` to generate GRAPH_REPORT.md and name communities
[graphify extract] incremental scan of /home/user/video_manager
[graphify extract] --code-only: skipping 153 non-code file(s) (140 docs, 0 papers, 13 images) — no LLM extraction
[graphify extract] 307 code, 0 docs, 0 papers, 0 images changed; 43 unchanged; 0 deleted
[graphify extract] 18 file(s) not classified (no supported extension or shebang), skipped: .editorconfig, .env.example, .gitattributes, .gitignore, .graphifyignore, .htaccess (+12 more)
[graphify extract] 1 file(s) skipped as potentially sensitive (rename or move if wrongly flagged): .npmrc
[graphify extract] AST extraction on 307 code files...
  AST cache: 265/307 files served from cache (86%); 42 to extract
  AST extraction: 42/42 uncached files (100%) [6 workers]
[graphify] Deduplicated 5 node(s) (5 exact).
[graphify] backed up curated graph (6 files) -> 2026-11-01/
[graphify extract] wrote /home/user/video_manager/graphify-out/graph.json: 3588 nodes, 6053 edges, 319 communities
[graphify extract] wrote /home/user/video_manager/graphify-out/.graphify_analysis.json
[graphify extract] incremental summary: 43 files cached/unchanged, 307 re-extracted, 0 deleted
[graphify extract] next: run `graphify cluster-only /home/user/video_manager` to generate GRAPH_REPORT.md and name communities
Usage:  docker compose [OPTIONS] COMMAND

Define and run multi-container applications with Docker

Options:
      --all-resources              Include all resources, even those not used by services
      --ansi string                Control when to print ANSI control characters ("never"|"always"|"auto") (default "auto")
      --compatibility              Run compose in backward compatibility mode
      --dry-run                    Execute command in dry run mode
      --env-file stringArray       Specify an alternate environment file
  -f, --file stringArray           Compose configuration files
      --parallel int               Control max parallelism, -1 for unlimited (default -1)
      --profile stringArray        Specify a profile to enable
      --progress string            Set type of progress output (auto, tty, plain, json, quiet)
      --project-directory string   Specify an alternate working directory
                                   (default: the path of the, first specified, Compose file)
  -p, --project-name string        Project name

Management Commands:
  bridge                  Convert compose files into another model

Commands:
  attach                  Attach local standard input, output, and error streams to a service's running container
  build                   Build or rebuild services
  commit                  Create a new image from a service container's changes
  config                  Parse, resolve and render compose file in canonical format
  cp                      Copy files/folders between a service container and the local filesystem
  create                  Creates containers for a service
  down                    Stop and remove containers, networks
  events                  Receive real time events from containers
  exec                    Execute a command in a running container
  export                  Export a service container's filesystem as a tar archive
  images                  List images used by the created containers
  kill                    Force stop service containers
  logs                    View output from containers
  ls                      List running compose projects
  pause                   Pause services
  port                    Print the public port for a port binding
  ps                      List containers
  publish                 Publish compose application
  pull                    Pull service images
  push                    Push service images
  restart                 Restart service containers
  rm                      Removes stopped service containers
  run                     Run a one-off command on a service
  scale                   Scale services 
  start                   Start services
  stats                   Display a live stream of container(s) resource usage statistics
  stop                    Stop services
  top                     Display the running processes
  unpause                 Unpause services
  up                      Create and start containers
  version                 Show the Docker Compose version information
  volumes                 List volumes
  wait                    Block until containers of all (or specified) services stop.
  watch                   Watch build context for service and rebuild/refresh containers when files are updated

Run 'docker compose COMMAND --help' for more information on a command.
