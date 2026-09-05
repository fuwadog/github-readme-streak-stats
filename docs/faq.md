# FAQ

## How do I create a Readme for my profile?

A profile readme appears on your profile page when you create a repository with the same name as your username and add a `README.md` file to it. For example, the repository for the user [`DenverCoder1`](https://github.com/DenverCoder1) is located at [`DenverCoder1/DenverCoder1`](https://github.com/DenverCoder1/DenverCoder1).

## How do I include GitHub Readme Streak Stats in my Readme?

Markdown files on GitHub support embedded images using Markdown or HTML. The public canonical service is `https://github-readme-streak-stats-black-phi.vercel.app`; use its `/demo/` route to customize a card and use the image source in either of the following ways. Preview and deployment URLs protected by Vercel Authentication or password protection are not public embed hosts.

The `https://streak-stats.demolab.com` hostname is retained as a legacy compatibility alias for existing embeds. Use `https://github-readme-streak-stats-black-phi.vercel.app` for new embeds.

### Markdown

```md
[![GitHub Streak](https://github-readme-streak-stats-black-phi.vercel.app/?user=DenverCoder1)](https://git.io/streak-stats)
```

### HTML

<!-- prettier-ignore-start -->
```html
<a href="https://git.io/streak-stats"><img src="https://github-readme-streak-stats-black-phi.vercel.app/?user=DenverCoder1"/></a>
```
<!-- prettier-ignore-end -->

## Why doesn't my Streak Stats match my contribution graph?

GitHub Readme Streak Stats uses the GitHub API to fetch your contribution data. These stats are returned in UTC time which may not match your local time. Additionally, due to caching, the stats may not be updated immediately after a commit. You may need to wait up to a few hours to see the latest stats.

If you think your stats are not showing up because of a time zone issue, you can try one of the following:

1. Change the date of the commit. You can [adjust the time](https://codewithhugo.com/change-the-date-of-a-git-commit/) of a past commit to make it in the middle of the day.
2. Create a new commit in a repository with the date set to the date that is missing from your streak stats:

```bash
git commit --date="2022-08-02 12:00" -m "Test commit" --allow-empty
git push
```

## What is considered a "contribution"?

Contributions include commits, pull requests, and issues that you create in standalone repositories ([Learn more about what is considered a contribution](https://docs.github.com/articles/why-are-my-contributions-not-showing-up-on-my-profile)).

The longest streak is the highest number of consecutive days on which you have made at least one contribution.

The current streak is the number of consecutive days ending with the current day on which you have made at least one contribution. If you have made a contribution today, it will be counted towards the current streak, however, if you have not made a contribution today, the streak will only count days before today so that your streak will not be zero.

> Note: You may need to wait up to 24 hours for new contributions to show up ([Learn how contributions are counted](https://docs.github.com/articles/why-are-my-contributions-not-showing-up-on-my-profile))

## How do I enable private contributions?

To include contributions in private repositories, turn on the setting for "Private contributions" from the dropdown menu above the contribution graph on your profile page.

## How do I center the image on the page?

To center align images, you must use the HTML syntax and wrap it in an element with the HTML attribute `align="center"`.

<!-- prettier-ignore-start -->
```html
<p align="center">
    <a href="https://git.io/streak-stats"><img src="https://github-readme-streak-stats-black-phi.vercel.app/?user=DenverCoder1"/></a>
</p>
```
<!-- prettier-ignore-end -->

## How do I make different images for dark mode and light mode?

You can [specify theme context](https://github.blog/changelog/2022-05-19-specify-theme-context-for-images-in-markdown-beta/) using the `<picture>` and `<source>` elements as shown below. The dark mode version appears in the `srcset` of the `<source>` tag and the light mode version appears in the `src` of the `<img>` tag.

<!-- prettier-ignore-start -->
```html
<picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://github-readme-streak-stats-black-phi.vercel.app/?user=DenverCoder1&theme=dark" />
    <img src="https://github-readme-streak-stats-black-phi.vercel.app/?user=DenverCoder1&theme=default" />
</picture>
```
<!-- prettier-ignore-end -->

## Why and how do I self-host GitHub Readme Streak Stats?

Self-hosting the code can be done online and only takes a couple minutes. The benefits include better uptime since it will use your own access token so will not run into ratelimiting issues and it allows you to customize the deployment for your own use case.

### [📺 Click here for a video tutorial on how to self-host on Vercel](https://www.youtube.com/watch?v=maoXtlb8t44)

See [Deploying it on your own](https://github.com/DenverCoder1/github-readme-streak-stats?tab=readme-ov-file#-deploying-it-on-your-own) in the Readme for detailed instructions.

### What does `WHITELIST` control?

`WHITELIST` is an explicit comma-separated list of GitHub usernames allowed by a deployment. Configure the exact usernames intended to be served; a non-listed username receives `403`. A missing, empty, wildcard, or malformed value fails closed, so every username receives `403` until a valid list is configured. This API policy is unrelated to GitHub collaborator permissions and does not grant anyone repository access.

### What are the cache, limiter, and token policies?

Successful cards are cacheable for the configured `CACHE_TTL` (which takes precedence over `CACHE_TTL_DEFAULT`); `DISABLE_CACHE=true` and all error responses use `no-store`, with a one-day fallback when no TTL is set. Self-hosted deployments use a file-based limit of 100 requests per minute per client IP. Vercel's isolated filesystem cannot provide a shared limiter, so use Vercel or an upstream WAF/API gateway for production abuse controls. `TOKEN` and `TOKEN2` through `TOKEN100` are server-side failover credentials only; rotate by adding the replacement, redeploying and verifying, then revoking and removing the old value. Never put token values in URLs, arguments, manifests, or logs.

### Can I use the demo offline, and is PNG available?

Yes. The demo uses fixture data and can be exercised offline without a GitHub token; keep it isolated from the card API and do not use production credentials in it. SVG works on Vercel. PNG requires Inkscape, so it is available on a self-hosted deployment that provides the renderer (preferably as an internal, resource-limited sidecar), but is not supported by the canonical Vercel deployment because Vercel's PHP behavior is unchanged and Inkscape is not installed there.
