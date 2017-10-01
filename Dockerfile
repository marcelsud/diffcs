FROM php:7

RUN curl -LO https://github.com/marcelsud/diffcs/releases/download/v0.2.1/diffcs.phar && chmod +x diffcs.phar && mv diffcs.phar /usr/local/bin/diffcs 
RUN curl -LO https://squizlabs.github.io/PHP_CodeSniffer/phpcs.phar && chmod +x phpcs.phar && mv phpcs.phar /usr/local/bin/phpcs

ENTRYPOINT ["diffcs"]
CMD ["--help"]
