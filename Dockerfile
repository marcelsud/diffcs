FROM php:7

RUN curl -LO https://github.com/marcelsud/diffcs/releases/download/v0.2.0/diffcs.phar && chmod +x diffcs.phar && mv diffcs.phar /usr/local/bin/diffcs 

ENTRYPOINT ["diffcs"]
CMD ["--help"]
