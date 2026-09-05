## Contributing Guidelines

Contributions are welcome! Feel free to open an issue or submit a pull request if you have a way to improve this project.

Make sure your request is meaningful and you have tested the app locally before submitting a pull request.

This documentation contains a set of guidelines to help you during the contribution process.

### Need some help regarding the basics?

You can refer to the following articles on the basics of Git and GitHub in case you are stuck:

- [Forking a Repo](https://help.github.com/en/github/getting-started-with-github/fork-a-repo)
- [Cloning a Repo](https://docs.github.com/en/repositories/creating-and-managing-repositories/cloning-a-repository)
- [How to create a Pull Request](https://opensource.com/article/19/7/create-pull-request-github)
- [Getting started with Git and GitHub](https://towardsdatascience.com/getting-started-with-git-and-github-6fcd0f2d4ac6)
- [Learn GitHub from Scratch](https://github.com/githubtraining/introduction-to-github)

### Installing Requirements

#### Requirements

- [PHP 8.2+](https://www.apachefriends.org/index.html)
- [Composer](https://getcomposer.org)
- PHP `curl` and `intl` extensions (required by the application)
- PHP `mbstring`, `dom`, `xml`, and `xmlwriter` extensions (required by PHPUnit)
- [Inkscape](https://inkscape.org) (required for the legacy local PNG fallback)
- Node.js 24.x (formatter, verification, and isolated renderer sidecar tooling)

The `composer coverage` command additionally needs a coverage driver such as PCOV or Xdebug; CI provides PCOV.

#### Linux

```bash
sudo apt-get install php
sudo apt-get install php-curl
sudo apt-get install php-intl
sudo apt-get install php-mbstring
sudo apt-get install php-xml
sudo apt-get install composer
sudo apt-get install inkscape
```

#### Windows

Install PHP from [XAMPP](https://www.apachefriends.org/index.html) or [php.net](https://windows.php.net/download)

Enable the `curl`, `intl`, `mbstring`, `dom`, `xml`, and `xmlwriter` extensions in `php.ini`. Install [Inkscape](https://inkscape.org) as well if you need PNG rendering.

[▶ How to install and run PHP using XAMPP (Windows)](https://www.youtube.com/watch?v=K-qXW9ymeYQ)

[📥 Download Composer](https://getcomposer.org/download/)

If your local PHP installation cannot provide the PHPUnit extensions, use the repository's [dev container](.devcontainer/) instead. It installs the required PHP extensions, Composer, and Node.js, then bootstraps the dependencies. The container supports `composer test`; use CI or add PCOV/Xdebug for `composer coverage`. After installation, `composer check-platform-reqs` verifies the available platform requirements.

Node.js 24.x is not the application runtime. Vercel continues to execute the existing PHP functions; Node is used only by verification and renderer tooling. PNG rendering is sidecar-first: use the isolated renderer over its private Unix socket with a read-only filesystem, no public listener or network egress, no GitHub credentials, and resource/time limits. The direct Inkscape invocation in the PHP process is an explicit legacy local fallback for self-hosted installations; use it only when the sidecar is unavailable and accept its larger process-isolation risk.

Run the checks from the sidecar host or image before cutover:

```bash
test "$(node --version)" = "v24.7.0"
inkscape --version | grep -q '^Inkscape '
```

### Clone the repository

```
git clone https://github.com/DenverCoder1/github-readme-streak-stats.git
cd github-readme-streak-stats
```

### Authorization

To get the GitHub API to run locally you will need to provide a token.

1. Visit [this link](https://github.com/settings/tokens/new?description=GitHub%20Readme%20Streak%20Stats) to create a new Personal Access Token
2. Scroll to the bottom and click **"Generate token"**
3. **Make a copy** of the `.env.example` named `.env` in the root directory and add **your token** after `TOKEN=`.

```php
TOKEN=<your-token>
```

Never place a token in a command argument, URL, test fixture, or log. Use `.env`, which is ignored, or a secret store. If `WHITELIST` is configured, use the exact approved GitHub usernames; collaborator status is not a substitute for this API allowlist.

### Install dependencies

Run the following command to install all the required dependencies to work on this project.

```bash
composer install
```

### Running the app locally

```bash
composer start
```

Open http://localhost:8000/?user=DenverCoder1 to run the project locally

Open http://localhost:8000/demo/ to run the demo site

### Running the tests

Run the following command to run the PHPUnit test script which will verify that the tested functionality is still working.

```bash
composer test
```

## Linting

This project uses Prettier for formatting PHP, Markdown, JavaScript and CSS files.

```bash
# Run prettier and show the files that need to be fixed
composer lint

# Run prettier and fix the files
composer lint-fix
```

## Submitting Contributions 👨‍💻

Below you will find the process and workflow used to review and merge your changes.

### Step 0 : Find an issue

- Take a look at the existing issues or create your **own** issues!

![issues tab](https://user-images.githubusercontent.com/63443481/136185624-24447858-de8d-4b0a-bb6b-2528d9031196.PNG)

### Step 1 : Fork the Project

- Fork this repository. This will create a copy of this repository on your GitHub profile.
  Keep the repository as a GitHub fork in the existing fork network and keep a reference to the original project in the `upstream` remote. Do not recreate it as an unrelated repository, since the deployment metadata and pull-request relationship depend on that network.

```bash
git clone https://github.com/<your-username>/github-readme-streak-stats.git
cd github-readme-streak-stats
git remote add upstream https://github.com/DenverCoder1/github-readme-streak-stats.git
```

For deployments, preserve the existing metadata while syncing the fork: the Vercel production branch is `vercel`, the repository root is the project root, and the `vercel.json` routes must remain unchanged. Keep production tokens in the hosting provider's secret store; do not add them to Git, issue reports, or commands.

In Vercel, use encrypted Environment Variables for `TOKEN`, `TOKEN2`-style failover values, and the explicit `WHITELIST`. Protect preview deployments with Vercel Authentication or password protection. This deployment access control is separate from `WHITELIST` (which controls requested GitHub usernames) and from GitHub collaborator permissions (which control repository actions); changing one must not be treated as changing either of the others.

Before a production cutover, run the sidecar health checks and compare fixed, sanitized requests against the canonical deployment and the candidate: status code, content type, SVG structure, and PNG bytes when PNG is supported. Exercise the demo separately with no token and confirm it uses fixture data. A differential mismatch blocks cutover until explained; do not print tokens or full request URLs in the comparison output.

#### Manual Vercel release-evidence checklist

These are manual evidence requirements, not claims that the checks have already been performed. Record the project and deployment identifiers, source commit, and production branch as provenance, without recording secrets or full request URLs.

- Confirm `TOKEN` and all `TOKENn` failover values are encrypted hosting-provider Environment Variables, never repository files, URLs, arguments, fixtures, or logs.
- Confirm the user-managed production `WHITELIST` still contains `fuwadog` and has not been changed by the release check. Do not edit that list as part of this task; verify denial for a non-listed user separately.
- Confirm the Vercel Hobby project has its single WAF rule configured deliberately; retain evidence of the client-IP or JA4 key, 10-second-to-10-minute window, and regional scope.
- Confirm Vercel Authentication or password protection is enabled for every preview and preview deployment URL. Only the public production URL may be used in README embeds.
- Confirm the `vercel` production branch, repository root, route contract, and one-function PHP deployment remain unchanged. Compare sanitized production requests for status, content type, and the controlled SVG fallback; do not expose tokens or full request URLs.
- Do not commit, push, or deploy while performing these checks; repository fail-closed tests do not substitute for this deployed Vercel evidence.

![fork button](https://user-images.githubusercontent.com/63443481/136185816-0b6770d7-0b00-4951-861a-dd15e3954918.PNG)

- If you have already forked the project, update your copy before working.

```bash
git remote update
git checkout <branch-name>
git rebase upstream/<branch-name>
```

### Step 2 : Branch

Create a new branch. Use its name to identify the issue you're addressing.

```bash
# Creates a new branch with the name feature_name and switches to it
git checkout -b feature_name
```

### Step 3 : Work on the issue assigned

- Work on the issue(s) assigned to you.
- Make all the necessary changes to the codebase.
- After you've made changes or made your contribution to the project, add changes to the branch you've just created using:

```bash
# To add all new files to the branch
git add .

# To add only a few files to the branch
git add <some files (with path)>
```

### Step 4 : Commit

- Commit a descriptive message using:

```bash
# This message will get associated with all files you have changed
git commit -m "message"
```

### Step 5 : Work Remotely

- Now you are ready to your work on the remote repository.
- When your work is ready and complies with the project conventions, upload your changes to your fork:

```bash
# To push your work to your remote repository
git push -u origin Branch_Name
```

- Here is how your branch will look.

  ![forked branch](https://user-images.githubusercontent.com/63443481/136186235-204f5c7a-1129-44b5-af20-89aa6a68d952.PNG)

### Step 6 : Pull Request

- Go to your forked repository in your browser and click on "Compare and pull request". Then add a title and description to your pull request that explains your contribution.

<img width="700" alt="compare and pull request" src="https://user-images.githubusercontent.com/63443481/136186304-c0a767ea-1fd2-4b0c-b5a8-3e366ddc06a3.PNG">

<img width="882" alt="opening pull request" src="https://user-images.githubusercontent.com/63443481/136186322-bfd5f333-136a-4d2f-8891-e8f97c379ba8.PNG">

- Voila! Your Pull Request has been submitted and it's ready to be merged.🥳

#### Happy Contributing!
