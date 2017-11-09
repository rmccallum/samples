# Zend Framework Example

This was an existing project that was originally a concept site that evolved into an actual project.

Being the 4th developer on it I was tasked with getting it to production as best as possible.

## Data Augmentation

By using the same data in a number of different formats and views I created an augmentData function that accepts a JSON encoded data set that is designed to augment the same data in a number of ways to meet the need of any given view. Keeping the original data intact is important and the augment function only manipulates the data represnted to the view state for any given page.

## The client site

https://dyadey.com

The site is a social media aggregator where a number of communities exist for a particular area of interest: Manchester United, Louis Vuitton, David Beckham. Each community will have it's social media URLs stored (Facebook, Twitter, Instagram, GooglePlus, Youtube) as hubs and these endpoints are scraped using the native APIs to store any new content and display that to the user if they have joined the community. Users are also able to add their own content and reply to any of the posts that are displayed.

## Legacy work

Coming into an already existing framework and not having the full project scope (financially or time-permitting) I was tasked to get the site working to a high standard as the client dmenaded with changing end goals I had to make the site work and get it to a production-ready level within the time constraints given for the project. 
