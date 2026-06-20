.PHONY: docker-start docker-stop

docker-start:
	docker compose up -d

docker-stop:
	docker compose down
