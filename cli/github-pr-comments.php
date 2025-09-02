<?php

require __DIR__ . '/../vendor/autoload.php';

use Joomla\Console\Application;
use Joomla\Console\Command\AbstractCommand;
use Joomla\Http\HttpFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Dotenv\Dotenv;

// Load environment variables
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');


class GithubCommentsCli extends AbstractCommand
{
    protected static $defaultName        = 'github-pr-comments';
    protected static $defaultDescription = 'Fetch merged PR comments by milestone + filter by phrase';

    public function __construct()
    {
        parent::__construct();
        $this->getDefinition()->addOption(new InputOption('token', null, InputOption::VALUE_OPTIONAL, 'GitHub token'));
        $this->getDefinition()->addOption(new InputOption('owner', null, InputOption::VALUE_OPTIONAL, 'GitHub owner'));
        $this->getDefinition()->addOption(new InputOption('repo', null, InputOption::VALUE_OPTIONAL, 'GitHub repository'));
        $this->getDefinition()->addOption(new InputOption('base', null, InputOption::VALUE_OPTIONAL, 'PR base branch'));
        $this->getDefinition()->addOption(new InputOption('milestone', null, InputOption::VALUE_OPTIONAL, 'PR milestone'));
        // Accept multiple --keyword entries
        $this->getDefinition()->addOption(
            new InputOption('keyword', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter keyword(s)')
        );
        // Date filter for merged PRs (optional)
        $this->getDefinition()->addOption(new InputOption('merged-since', null, InputOption::VALUE_OPTIONAL, 'Date filter for merged PRs (YYYY-MM-DD)'));
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $token       = null !== $input->getOption('token') ? $input->getOption('token') : $_ENV['GITHUB_TOKEN'] ?? null;
        $owner       = null !== $input->getOption('owner') ? $input->getOption('owner') : $_ENV['GITHUB_OWNER'] ?? null;
        $repo        = null !== $input->getOption('repo') ? $input->getOption('repo') : $_ENV['GITHUB_REPO'] ?? null;
        $base        = null !== $input->getOption('base') ? $input->getOption('base') : $_ENV['GITHUB_BASE'] ?? '6.0-dev';
        $milestone   = null !== $input->getOption('milestone') ? $input->getOption('milestone') : $_ENV['GITHUB_MILESTONE'] ?? 'Joomla! 6.0.0';
        $mergedSince = null !== $input->getOption('merged-since') ? $input->getOption('merged-since') : null;

        // Validate date filter if present
        if ($mergedSince && (!($date = \DateTime::createFromFormat('Y-m-d', $mergedSince)) || $date->format('Y-m-d') !== $mergedSince)) {
            $output->writeln('<error>Invalid date format for --merged-since. Please use YYYY-MM-DD.</error>');
            return 1;
        }

        // Build keywords list: use CLI options if provided, otherwise env
        $cliKeywords = $input->getOption('keyword');
        if (\is_array($cliKeywords) && \count($cliKeywords) > 0) {
            $keywords = $cliKeywords;
        } else {
            $envList = $_ENV['GITHUB_KEYWORDS'] ?? null;
            if ($envList) {
                $keywords = array_map('trim', explode(',', $envList));
            } else {
                $keywords = [];
            }
        }

        // Initialize collection by author
        $collected = [];
        $client    = (new HttpFactory())->getHttp([
            'headers' => [
                'Authorization' => 'bearer ' . $token,
                'User-Agent'    => 'joomla-cli-gql',
                'Content-Type'  => 'application/json',
            ],
        ]);

        // Prepare GraphQL search query with milestone filter
        $query = <<<'GQL'
query($queryString:String!,$after:String){
  search(query:$queryString,type:ISSUE,last:100,after:$after){
    pageInfo{hasNextPage,endCursor}
    nodes{ ... on PullRequest {
        number
        title
        milestone{ title }
        comments(last:100){ nodes{ author{login} body createdAt } }
    }}
  }
}
GQL;

        $after = null;
        do {
            // Build search string including milestone
            $queryString = \sprintf('repo:%s/%s is:pr is:merged base:%s milestone:"%s"', $owner, $repo, $base, $milestone);
            // Add date filter if provided
            if ($mergedSince) {
                $queryString .= ' merged:>=' . $mergedSince;
            }
            $payload     = ['query' => $query, 'variables' => ['queryString' => $queryString, 'after' => $after]];
            $response    = $client->post('https://api.github.com/graphql', json_encode($payload));
            $data        = json_decode($response->getBody());

            if ($response->getStatusCode() !== 200) {
                $output->writeln('<error>Error fetching data: ' . $response->getReasonPhrase() . '<br>token: ' . $token . '</error>');
                return 1;
            }

            if (isset($data->errors)) {
                $output->writeln('<error>Error fetching data: ' . $data->errors[0]->message . '</error>');
                return 1;
            }

            $outputResults = ($mergedSince) ?
                \sprintf(
                    "Found %d PRs merged since %s in %s/%s with milestone '%s':",
                    \count($data->data->search->nodes),
                    $mergedSince,
                    $owner,
                    $repo,
                    $milestone
                ) : \sprintf(
                    "Found %d PRs in %s/%s with milestone '%s':",
                    \count($data->data->search->nodes),
                    $owner,
                    $repo,
                    $milestone
                );

            $output->writeln($outputResults);

            foreach ($data->data->search->nodes as $pr) {

                foreach ($pr->comments->nodes as $comment) {
                    // Only print if all defined keywords are present
                    $allFound = true;
                    foreach ($keywords as $kw) {
                        if (stripos($comment->body, $kw) === false) {
                            $allFound = false;
                            break;
                        }
                    }
                    if (! $allFound) {
                        continue;
                    }
                    // Collect comment under author login, keyed by PR number to avoid duplicates
                    $author = $comment->author?->login;

                    if ($comment->author === null) {
                        continue;
                    }

                    $prKey  = $pr->number;
                    if (! isset($collected[$author][$prKey])) {
                        $collected[$author][$prKey] = [
                            'pr'        => $prKey,
                            'title'     => $pr->title,
                            'comment'   => $comment->body,
                            'createdAt' => $comment->createdAt,
                        ];
                    }
                }
            }

            $pageInfo = $data->data->search->pageInfo;
            $hasNext  = $pageInfo->hasNextPage;
            $after    = $pageInfo->endCursor;
        } while ($hasNext);

        // Sort authors
        uksort($collected, 'strcasecmp');

        // Write markdown output
        $md = "## :technologist: Test contributions\n\n";
        $md .= "Thank you to all the testers who help us maintain high quality standards and deliver a robust product.\n\n";
        $mdFull          = $md;
        $contributorList = [];
        // Output collected comments
        foreach ($collected as $author => $comments) {
            $countTests        = \count($comments);
            $contributorList[] = "@{$author} ({$countTests})";
            $mdFull .= "- @{$author} ({$countTests})\n";
            $output->writeln(\sprintf("Tests by %s:", $author));
            foreach ($comments as $comment) {
                $mdFull .= "    - PR #{$comment['pr']}: {$comment['title']}\n";
                $output->writeln(\sprintf(" - PR #%d: %s", $comment['pr'], $comment['title']));
            }
        }
        $md .= implode(', ', $contributorList) . "\n";

        file_put_contents(__DIR__ . '/../collaborator-tester.md', $md);
        file_put_contents(__DIR__ . '/../collaborator-tester-full.md', $mdFull);

        return 0;
    }
}

// Bootstrap Joomla Console application
$app = new Application();
$app->addCommand(new GithubCommentsCli());
$app->execute();
