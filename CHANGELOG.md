# Changelog: zerofront/module-zero-blog

## 1.3.6

- A post scheduled for later appears on its own: every five minutes the cron `zero_blog_purge_scheduled_posts` cleans the blog lists, the products' "From the blog" blocks and the cached 404 of the posts that came out.
- The admin's post and comment grids filter again on columns the joined tables share (no more "ambiguous column" errors).

Earlier releases (1.3.5 and before): see the Git tags.
