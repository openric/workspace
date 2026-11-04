---
title: Article comments
category: Public site
order: 30
staff: true
routes: site.comments*
heratio: blog-comments-moderation-user-guide.md
---

# Article comments

Visitors can comment on an article, without an account. A comment is shown under the article as soon as it is posted, as in Heratio, and editors take down what should not be there.

## What stops abuse

- A hidden field that only automated robots fill in: a comment with it filled is dropped without a word.
- A pause of 20 seconds between two comments from one address.
- At most six comments a minute from one address, and 4,000 characters a comment.
- No HTML: a comment is shown as plain text.

## Moderating

**Article comments** (`/site/articles/comments`) lists the latest comments, newest first, with the article, the name given (or *Anonymous*), the text and the address it came from. For each, set its status:

- **Approved**: shown under the article;
- **Pending**: held, off the article, until someone looks at it again;
- **Spam**: kept out of sight.

**Delete** removes it altogether. The articles list shows how many comments are waiting.
