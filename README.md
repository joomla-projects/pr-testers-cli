# Github PR Comments Tests CLI

## What it does

The `github-pr-comments` CLI command fetches merged pull request comments from a GitHub repository filtered by milestone and keywords. It aggregates tester contributions and outputs a markdown report of who tested which PRs.

### Key Features
- Fetches only **merged PRs** for a specific repository, base branch, and milestone.
- Supports **keyword filtering**, requiring one or more phrases to appear in each comment.  
- Collects and groups comments by **author**, ensuring each PR is listed once per tester.  
- Outputs results to the console and writes a **`collaborator-tester.md`** markdown file.  
- Configurable via CLI options or environment variables for maximum flexibility.  

## How to use

1. **Clone the repository**
    ```bash
    git clone https://github.com/your_org/your_repo.git
    ```

2. **Install dependencies** with Composer:
   ```bash
   composer install
   ```

3. **Configure** `.env` in the project root:
   ```ini
   GITHUB_TOKEN=your_token_here
   GITHUB_OWNER=your_org
   GITHUB_REPO=your_repo
   GITHUB_BASE=main
   GITHUB_MILESTONE=v1.0.0
   GITHUB_KEYWORDS="I have tested this item, OK to merge"
   ```
    - Replace `your_token_here` with your GitHub personal access token.

   You can find an example `.env` file in this respository. So you could also simply copy this and adapt it to your needs.

   ```bash
   cp .env.example .env
   ```
  > [!TIP]
  > Store your GitHub token in a CI secret or environment variable rather than in `.env` to keep it secure. Ensure your token has read-only permissions (e.g., `public_repo`).

4. **Run** the command:
   ```bash
   php cli/github-pr-comments.php \
     --token=your_token_here \
     --owner=your_org \
     --repo=your_repo \
     --base=main \
     --milestone=v1.0.0 \
     --keyword="I have tested this item" \
     --keyword="OK to merge"
   ```

## How it works

1. **Authentication** via GitHub token in header.
2. **GraphQL search** queries GitHub API for issues of type PR matching repo, base branch, and milestone.
3. **Pagination** loops until all results are fetched (handles `hasNextPage`).
4. **Filtering** iterates PR comments and checks if **all keywords** are present.
5. **Aggregation** groups unique PR entries per author to avoid duplicates.
6. **Output** writes a human-readable list to console and a markdown report file.

## Example

```bash
php cli/github-pr-comments.php --milestone=v1.0.0 --keyword="tested" --keyword="LGTM"
```

Get a list of all possible options:
```bash
php cli/github-pr-comments.php github-pr-comments --help
```

**Result**
- Console prints:
  - Tests by alice:
    - PR #123: Fix typo
    - PR #124: Improve validation
  - Tests by bob:
    - PR #122: Add logging

- `collaborator-tester.md` is created with the grouped results.

  > ## :technologist: Test contributions
  > Thank you to all the testers who help us maintain high quality standards and deliver a robust product.
  > 
  > @alice (2), @bob (1)
