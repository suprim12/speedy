# CloudFront

Speedy clears CloudFront when pages change, with no AWS SDK, and keeps the cost down.

Speedy detects CloudFront from its `X-Amz-Cf-Id` or `Via` header and says so on the settings and utility pages.

## Without keys

Speedy still caches on your server, and tells CloudFront not to keep HTML pages (`Cache-Control: no-cache, s-maxage=0`), so CloudFront never shows a page Speedy can’t clear.

## With keys

1. In AWS IAM, create a user (or role) with this policy, using your distribution’s ARN:
   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       {
         "Effect": "Allow",
         "Action": "cloudfront:CreateInvalidation",
         "Resource": "arn:aws:cloudfront::123456789012:distribution/E1ABCDEF2GHIJK"
       }
     ]
   }
   ```
2. Create an access key for it, and add it to `.env` with the distribution ID:
   ```bash
   CLOUDFRONT_DISTRIBUTION_ID="E1ABCDEF2GHIJK"
   AWS_ACCESS_KEY_ID="…"
   AWS_SECRET_ACCESS_KEY="…"
   ```
3. In **Settings → Plugins → Speedy Cache**, enter `$CLOUDFRONT_DISTRIBUTION_ID`. The AWS keys are read from `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` when their fields are blank. `AWS_SESSION_TOKEN` is used when it’s set.
4. On the utility page, click **Test CloudFront**. It sends one invalidation for a path no page uses.

Make sure your CloudFront cache policy respects the origin’s `Cache-Control` header, so pages are kept for as long as Speedy says.

## Keeping it free

CloudFront includes 1,000 invalidation paths a month, then charges per path. Speedy:

- sends one path per changed page;
- when one clear has more than 30 paths (`wildcardThreshold`), groups them by first folder: `/news/a`, `/news/b` and `/news/c` become `/news*`;
- if there are still too many, sends `/*`, which counts as one path;
- sends `/*` for **Clear all pages**;
- counts the paths used this month, shown on the utility page.

## Failures

Invalidation errors, such as a wrong distribution ID, are shown on the utility page and in the log. Pages on your server are still cleared and rebuilt.
